<?php

namespace App\Filament\Pages;

use App\Filament\Resources\InformationRequestResource;
use App\Filament\Resources\InterventoProgrammatoResource;
use App\Models\InformationRequestAppointment;
use App\Models\InterventoProgrammato;
use App\Support\DisplayName;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Il calendario di quello che e' stato promesso: appuntamenti sulle
 * richieste informazioni e lavori in programmazione, sugli stessi giorni.
 *
 * Prima gli appuntamenti si vedevano solo aprendo la singola richiesta, uno
 * alla volta. Non c'era nessun posto per guardarli tutti insieme, ed e' il
 * motivo per cui cinque sono passati senza che nessuno chiudesse il cerchio
 * — Rose & Crown del 4 settembre e Hotel San Marco dell'8 sono ancora
 * "nuova" oggi, 04/11/2026.
 *
 * GLI SCADUTI STANNO IN CIMA, SEMPRE. Un calendario mostra il mese che
 * stai guardando, e quei cinque sono in mesi che nessuno riaprira' mai:
 * lasciarli solo nella loro casella di settembre vorrebbe dire nasconderli
 * di nuovo. La fascia li tiene davanti agli occhi in qualunque mese ti
 * trovi, e sparisce quando non ce ne sono.
 */
class Agenda extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    // In Vendite e non in Interventi tecnici: quello che ci sta dentro sono
    // appuntamenti presi su richieste informazioni, cioe' lavoro
    // commerciale. I lavori dei tecnici ci compaiono di contorno
    // (Laura, 04/11/2026).
    protected static ?string $navigationGroup = 'Vendite';

    // In cima al gruppo: l'agenda si apre al mattino, prima dei clienti e
    // delle richieste.
    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Agenda';

    protected static ?string $title = 'Agenda';

    protected static ?string $slug = 'agenda';

    protected static string $view = 'filament.pages.agenda';

    public ?int $mese = null;

    public ?int $anno = null;

    /**
     * Le voci del mese, tenute per la durata del render.
     *
     * La vista le chiede una volta per cella — quarantadue celle — e senza
     * questa cache sarebbero quarantadue query uguali.
     *
     * @var Collection<array-key, Collection<int, mixed>>|null
     */
    protected ?Collection $voci = null;

    /** @var Collection<int, array<string, mixed>>|null */
    protected ?Collection $scaduti = null;

    public function mount(): void
    {
        $oggi = Carbon::today();
        $this->mese ??= $oggi->month;
        $this->anno ??= $oggi->year;
    }

    public function getSubheading(): ?string
    {
        return 'Appuntamenti presi sulle richieste e lavori in programmazione, sugli stessi giorni.';
    }

    public function mesePrecedente(): void
    {
        $m = $this->primoDelMese()->subMonth();
        $this->mese = $m->month;
        $this->anno = $m->year;
    }

    public function meseSuccessivo(): void
    {
        $m = $this->primoDelMese()->addMonth();
        $this->mese = $m->month;
        $this->anno = $m->year;
    }

    public function oggi(): void
    {
        $oggi = Carbon::today();
        $this->mese = $oggi->month;
        $this->anno = $oggi->year;
    }

    public function primoDelMese(): Carbon
    {
        return Carbon::create($this->anno ?: Carbon::today()->year, $this->mese ?: Carbon::today()->month, 1)
            ->startOfMonth();
    }

    public function titoloMese(): string
    {
        return ucfirst($this->primoDelMese()->translatedFormat('F Y'));
    }

    /**
     * I quarantadue giorni della griglia: dal lunedi' della settimana in cui
     * cade il primo, alla domenica di quella in cui cade l'ultimo.
     *
     * @return array<int, Carbon>
     */
    public function giorniDellaGriglia(): array
    {
        $inizio = $this->primoDelMese()->copy()->startOfWeek(Carbon::MONDAY);
        $fine = $this->primoDelMese()->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $giorni = [];
        for ($g = $inizio->copy(); $g->lte($fine); $g->addDay()) {
            $giorni[] = $g->copy();
        }

        return $giorni;
    }

    /**
     * Le voci di un giorno, gia' ordinate per ora.
     *
     * @return Collection<int, mixed>
     */
    public function vociDelGiorno(Carbon $giorno): Collection
    {
        return $this->tutteLeVoci()->get($giorno->toDateString(), collect());
    }

    /** @return Collection<array-key, Collection<int, mixed>> */
    protected function tutteLeVoci(): Collection
    {
        if ($this->voci !== null) {
            return $this->voci;
        }

        $giorni = $this->giorniDellaGriglia();
        $da = $giorni[0];
        $a = end($giorni);

        $voci = collect();

        foreach (InformationRequestAppointment::query()
            ->whereBetween('scheduled_at', [$da->copy()->startOfDay(), $a->copy()->endOfDay()])
            ->with('informationRequest.customer')
            ->get() as $app) {
            $voci->push($this->daAppuntamento($app));
        }

        foreach (InterventoProgrammato::query()
            ->whereBetween('data', [$da->toDateString(), $a->toDateString()])
            ->with('customer')
            ->get() as $int) {
            $voci->push($this->daIntervento($int));
        }

        return $this->voci = $voci
            ->sortBy(fn (array $v) => $v['ordine'])
            ->groupBy('giorno');
    }

    /**
     * Gli appuntamenti passati su richieste ancora aperte.
     *
     * Non si guarda il mese: sono proprio quelli fuori dal mese corrente il
     * problema.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function appuntamentiScaduti(): Collection
    {
        if ($this->scaduti !== null) {
            return $this->scaduti;
        }

        // Conta solo l'ULTIMO appuntamento di ogni richiesta.
        //
        // Prima si elencava ogni appuntamento passato, e uno rifissato
        // restava in sospeso per sempre: Naturae Lodge aveva il 23/10
        // mancato e il 01/12 gia' preso, e compariva lo stesso. Se nel
        // frattempo e' stata fissata un'altra data, quella vecchia e' stata
        // gestita -- che e' esattamente cosa vuol dire rifissare
        // (Laura, 04/11/2026).
        $ultimi = InformationRequestAppointment::query()
            ->whereHas('informationRequest', fn ($q) => $q->whereIn('status', ['nuova', 'in_lavorazione']))
            ->with('informationRequest.customer')
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('information_request_id')
            ->map(fn ($gruppo) => $gruppo->last());

        return $this->scaduti = $ultimi
            ->filter(fn (InformationRequestAppointment $a) => $a->scheduled_at->lt(Carbon::today()->startOfDay()))
            ->sortBy('scheduled_at')
            ->map(fn (InformationRequestAppointment $a) => $this->daAppuntamento($a))
            ->values();
    }

    /** @return array<string, mixed> */
    protected function daAppuntamento(InformationRequestAppointment $a): array
    {
        // Le relazioni non dichiarano il tipo: senza queste due righe
        // l'analisi statica vede due Model generici e non trova i campi.
        /** @var \App\Models\InformationRequest|null $richiesta */
        $richiesta = $a->informationRequest;
        /** @var \App\Models\Customer|null $cliente */
        $cliente = $richiesta?->customer;

        $chi = DisplayName::titleCase($cliente?->company_name)
            ?: trim(($cliente->first_name ?? '').' '.($cliente->last_name ?? ''))
            ?: ($richiesta->number ?? 'Richiesta');

        return [
            'tipo' => 'appuntamento',
            'giorno' => $a->scheduled_at->toDateString(),
            'ordine' => $a->scheduled_at->format('H:i'),
            'ora' => $a->scheduled_at->format('H:i'),
            'chi' => $chi,
            'etichetta' => $richiesta?->number,
            'note' => $a->notes,
            'url' => $richiesta
                ? InformationRequestResource::getUrl('edit', ['record' => $richiesta->getKey()], tenant: Filament::getTenant())
                : null,
            'scaduto' => $a->scheduled_at->isPast()
                && in_array($richiesta?->status, ['nuova', 'in_lavorazione'], true),
        ];
    }

    /** @return array<string, mixed> */
    protected function daIntervento(InterventoProgrammato $i): array
    {
        return [
            'tipo' => 'intervento',
            'giorno' => $i->data->toDateString(),
            // Senza ora va in fondo alla giornata, non in cima.
            'ordine' => $i->ora ? substr((string) $i->ora, 0, 5) : 'zz',
            'ora' => $i->ora ? substr((string) $i->ora, 0, 5) : null,
            'chi' => $i->etichetta(),
            'etichetta' => InterventoProgrammato::tipi()[$i->tipo] ?? $i->tipo,
            'note' => $i->note,
            'url' => InterventoProgrammatoResource::getUrl('edit', ['record' => $i->id], tenant: Filament::getTenant()),
            'scaduto' => $i->inRitardo(),
        ];
    }
}
