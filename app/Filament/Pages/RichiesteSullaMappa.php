<?php

namespace App\Filament\Pages;

use App\Filament\Resources\InformationRequestResource;
use App\Models\InformationRequest;
use App\Support\DisplayName;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Le richieste informazioni ancora aperte, su una mappa.
 *
 * Nasce da una domanda pratica: "scelgo una zona e vado a trovarli". In
 * elenco non si vedeva, perche' sessantasei righe ordinate per data non
 * dicono che tredici di quelle sono tutte in provincia di Belluno e si
 * fanno in una giornata sola.
 *
 * I due appuntamenti mancati del 23/10 — Baita Flora Alpina a Selva di
 * Cadore e Naturae Lodge ad Alleghe — stanno a venti minuti dall'Hotel
 * Alle Alpi, dove si va comunque l'11 novembre. Questo e' il genere di
 * cosa che si vede solo su una mappa (Laura, 04/11/2026).
 */
class RichiesteSullaMappa extends Page
{
    use HasPageShield;

    /** Gli stati che vuol dire "aperta": c'e' ancora qualcosa da fare. */
    private const APERTE = ['nuova', 'in_lavorazione', 'preventivo_inviato'];

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Richieste sulla mappa';

    protected static ?string $title = 'Richieste sulla mappa';

    protected static ?string $slug = 'richieste-mappa';

    protected static string $view = 'filament.pages.richieste-sulla-mappa';

    /** Provincia selezionata, o null per tutte. */
    public ?string $provincia = null;

    /** @var Collection<int, mixed>|null */
    protected ?Collection $righe = null;

    public function getSubheading(): ?string
    {
        return 'Dove sono le richieste ancora aperte: si sceglie una zona e si va a trovarli.';
    }

    /**
     * Le richieste aperte con un cliente localizzato.
     *
     * @return Collection<int, mixed>
     */
    public function richieste(): Collection
    {
        if ($this->righe !== null) {
            return $this->righe;
        }

        $oggi = Carbon::today();

        return $this->righe = InformationRequest::query()
            ->whereIn('status', self::APERTE)
            ->whereHas('customer', fn ($q) => $q->whereNotNull('latitude')->whereNotNull('longitude'))
            ->with('customer')
            ->get()
            ->when($this->provincia, fn (Collection $c) => $c->filter(
                function (InformationRequest $r): bool {
                    /** @var \App\Models\Customer|null $cliente */
                    $cliente = $r->customer;

                    return $cliente?->province === $this->provincia;
                }
            ))
            ->map(function (InformationRequest $r) use ($oggi): array {
                /** @var \App\Models\Customer $cliente */
                $cliente = $r->customer;

                return [
                    'numero' => $r->number,
                    'nome' => DisplayName::titleCase($cliente->company_name)
                        ?: trim(($cliente->first_name ?? '').' '.($cliente->last_name ?? ''))
                        ?: $r->number,
                    'citta' => $cliente->city,
                    'provincia' => $cliente->province,
                    'lat' => (float) $cliente->latitude,
                    'lng' => (float) $cliente->longitude,
                    // I giorni di attesa sono la cosa che decide chi vedere
                    // prima, piu' dello stato.
                    'giorni' => (int) $r->created_at->diffInDays($oggi),
                    'appuntamento' => $r->appointment_at?->format('d/m/Y'),
                    'appuntamentoFuturo' => $r->appointment_at?->isFuture() ?? false,
                    'stato' => $r->status,
                    // Il colore e' quello che rende la mappa utilizzabile da
                    // soli. L'ordine conta: prima dove si va gia', poi chi
                    // aspetta una risposta da noi, poi chi aspetta e basta.
                    //
                    // Il viola sono i preventivi mandati e non ancora
                    // accettati: li' la palla e' nel loro campo, ma e' anche
                    // la gente che una telefonata la merita piu' di tutti.
                    //
                    // Viola e non arancione: l'arancione accanto al rosso non
                    // si distingueva (Laura, 04/11/2026). E il pallino e' piu'
                    // grosso, cosi' la differenza si vede anche senza fidarsi
                    // del colore.
                    'colore' => match (true) {
                        $r->appointment_at?->isFuture() ?? false => '#16a34a',
                        $r->status === 'preventivo_inviato' => '#9333ea',
                        $r->created_at->diffInDays($oggi) > 60 => '#dc2626',
                        default => '#2563eb',
                    },
                    'raggio' => $r->status === 'preventivo_inviato' ? 12 : 9,
                    'url' => InformationRequestResource::getUrl('edit', ['record' => $r->getKey()], tenant: Filament::getTenant()),
                ];
            })
            ->sortByDesc('giorni')
            ->values();
    }

    /**
     * Le province dove c'e' almeno una richiesta aperta, con quante sono.
     *
     * Si contano SEMPRE su tutte, non sul filtro attivo: altrimenti
     * scegliendo una provincia sparirebbero le altre dal menu e non si
     * potrebbe piu' cambiare.
     *
     * @return array<string, string>
     */
    public function province(): array
    {
        $conteggi = InformationRequest::query()
            ->whereIn('status', self::APERTE)
            ->whereHas('customer', fn ($q) => $q->whereNotNull('latitude'))
            ->with('customer:id,province')
            ->get()
            ->groupBy(function (InformationRequest $r): string {
                /** @var \App\Models\Customer|null $cliente */
                $cliente = $r->customer;

                return $cliente?->province ?: '—';
            })
            ->map->count()
            ->sortDesc();

        $opzioni = [];
        foreach ($conteggi as $sigla => $quante) {
            $opzioni[$sigla] = $sigla.' ('.$quante.')';
        }

        return $opzioni;
    }

    /** Quante richieste aperte non si possono mostrare perche' senza coordinate. */
    public function senzaPosizione(): int
    {
        return InformationRequest::query()
            ->whereIn('status', self::APERTE)
            ->whereHas('customer', fn ($q) => $q->whereNull('latitude'))
            ->count();
    }

    public function tutteLeZone(): void
    {
        $this->provincia = null;
    }
}
