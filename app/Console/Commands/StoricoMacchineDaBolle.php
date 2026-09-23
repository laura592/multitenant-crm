<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MachineUnitPlacement;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ricostruisce lo storico dei posizionamenti dalle bolle di Eureka
 * (23/09/2026).
 *
 * Il sync guarda solo l'ULTIMA bolla di ogni matricola, per proporre lo
 * spostamento, e butta via le precedenti: cosi' nello storico restano i
 * soli periodi che il CRM ha visto nascere, e i passaggi di mezzo
 * spariscono. Il macinadosatore 0819352 risultava sempre stato all'Hotel
 * Principe Palace, mentre la bolla 205 del 28/04/2025 lo dava all'Hotel
 * Venezia per un anno.
 *
 * Qui le bolle si mettono in fila: ognuna apre un periodo presso il suo
 * cliente, e lo chiude quella dopo. Si aggiungono i periodi che mancano e
 * si correggono le date di chiusura sbagliate; non si cancella mai un
 * posizionamento, non si tocca chi paga, e il cliente attuale della
 * macchina resta quello che e'.
 *
 * Sola lettura verso Eureka.
 */
class StoricoMacchineDaBolle extends Command
{
    protected $signature = 'macchine:storico-da-bolle
                            {--tenant=alex : slug del tenant}
                            {--matricola= : una sola matricola, invece di tutte}
                            {--dry-run : mostra prima/dopo senza scrivere}';

    protected $description = 'Ricostruisce lo storico dei posizionamenti dalle bolle di consegna di Eureka';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $clienti = Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('gestionale_code')
            ->get();

        $this->info('Leggo le consegne di '.$clienti->count().' clienti su Eureka: ci vuole qualche minuto.');

        $client = new EurekaClient($tenant);
        $parametri = $clienti->mapWithKeys(fn (Customer $c) => [$c->id => ['q' => (int) $c->gestionale_code]])->all();

        $risposte = $client->pooledGet('/show/q/art_installati', $parametri);
        $falliti = $client->chiaviPooledFallite();

        // Qualche chiamata cade sempre (Eureka ha i suoi 500 a raffica): si
        // richiedono solo quelle.
        for ($tentativo = 0; $tentativo < 2 && $falliti !== []; $tentativo++) {
            $this->line('  riprovo '.count($falliti).' clienti...');
            usleep(2_000_000);

            $ripetute = $client->pooledGet('/show/q/art_installati', array_intersect_key($parametri, array_flip($falliti)));
            $ancora = $client->chiaviPooledFallite();

            foreach ($ripetute as $chiave => $valore) {
                if (! in_array($chiave, $ancora, true)) {
                    $risposte[$chiave] = $valore;
                }
            }

            $falliti = $ancora;
        }

        // Con un elenco mancante mancherebbe un pezzo di storia, e le date di
        // chiusura verrebbero sbagliate: meglio non scrivere niente.
        if ($falliti !== []) {
            $this->error('Eureka non ha risposto per '.count($falliti).' clienti nemmeno riprovando: rilancia fra qualche minuto.');

            return self::FAILURE;
        }

        $bollePerMatricola = [];

        foreach ($risposte as $customerId => $righe) {
            foreach ((array) $righe as $riga) {
                $chiave = MachineUnit::chiaveMatricola((string) ($riga['matricola'] ?? ''));
                $data = $this->data($riga['data_documento'] ?? null);

                if ($chiave === '' || ! $data || preg_match('/^0+$/', $chiave)) {
                    continue;
                }

                $bollePerMatricola[$chiave][] = [
                    'cliente' => (string) $customerId,
                    'data' => $data,
                    'bolla' => (int) ($riga['numero_doc_t23'] ?? 0),
                    'pagante' => ((int) ($riga['id_intestatario_fattura_f15'] ?? 0)) ?: null,
                ];
            }
        }

        $macchine = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('serial_number')
            ->when($this->option('matricola'), fn ($q, $m) => $q->where('serial_number', $m))
            ->with(['placements' => fn ($q) => $q->orderBy('placed_at')])
            ->get();

        $nomi = $clienti->pluck('company_name', 'id');
        $daFare = [];
        $ambigue = [];

        foreach ($macchine as $macchina) {
            $bolle = $bollePerMatricola[MachineUnit::chiaveMatricola($macchina->serial_number)] ?? [];

            if (count($bolle) < 2) {
                continue;
            }

            $periodi = $this->periodi($bolle);

            if ($periodi === null) {
                $ambigue[] = $macchina;

                continue;
            }

            $lavoro = $this->confronta($macchina, $periodi);

            if ($lavoro['nuovi'] === [] && $lavoro['chiusure'] === [] && $lavoro['inizio'] === null) {
                continue;
            }

            $daFare[] = ['macchina' => $macchina] + $lavoro;
        }

        if ($ambigue !== []) {
            $this->warn('Saltate: due consegne lo stesso giorno da clienti diversi, non si sa in che ordine — '
                .collect($ambigue)->pluck('serial_number')->take(10)->implode(', '));
        }

        if ($daFare === []) {
            $this->info('Lo storico e\' gia\' quello che dicono le bolle.');

            return self::SUCCESS;
        }

        $this->table(
            ['Matricola', 'Periodi da aggiungere', 'Chiusure da correggere', 'Adesso e\' li\' dal'],
            collect($daFare)->map(fn (array $r) => [
                $r['macchina']->serial_number,
                collect($r['nuovi'])->map(fn (array $p) => $p['dal']->format('d/m/Y').' → '.DisplayName::titleCase($nomi[$p['cliente']] ?? '?'))->implode("\n") ?: '—',
                collect($r['chiusure'])->map(fn (array $c) => $c['riga']->placed_at->format('d/m/Y').': '
                    .($c['riga']->removed_at?->format('d/m/Y') ?? 'aperto').' → '.$c['nuova']->format('d/m/Y'))->implode("\n") ?: '—',
                $r['inizio']
                    ? $r['inizio']['riga']->placed_at->format('d/m/Y').' → '.$r['inizio']['nuovo']->format('d/m/Y')
                    : '—',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: '.count($daFare).' macchine da sistemare, niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Scrivo lo storico di '.count($daFare).' macchine?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($daFare as $r) {
            if ($r['inizio']) {
                $r['inizio']['riga']->update(['placed_at' => $r['inizio']['nuovo']]);
            }

            foreach ($r['chiusure'] as $chiusura) {
                $chiusura['riga']->update(['removed_at' => $chiusura['nuova']]);
            }

            foreach ($r['nuovi'] as $periodo) {
                MachineUnitPlacement::create([
                    'tenant_id' => $r['macchina']->tenant_id,
                    'machine_unit_id' => $r['macchina']->id,
                    'customer_id' => $periodo['cliente'],
                    'eureka_billing_customer_code' => $periodo['pagante'],
                    'placed_at' => $periodo['dal'],
                    'removed_at' => $periodo['al'],
                    'notes' => 'Ricostruito dalle bolle Eureka'.($periodo['bolla'] > 0 ? ', bolla n. '.$periodo['bolla'] : ''),
                ]);
            }
        }

        $this->info('Fatto: storico sistemato su '.count($daFare).' macchine.');

        return self::SUCCESS;
    }

    /**
     * Le bolle in fila: ognuna apre un periodo, la successiva lo chiude.
     * Null quando due clienti diversi hanno la stessa data (spesso il saldo
     * iniziale del 01/01): l'ordine non si puo' indovinare.
     *
     * @param  array<int, array{cliente: string, data: Carbon, bolla: int, pagante: ?int}>  $bolle
     * @return ?array<int, array{cliente: string, dal: Carbon, al: ?Carbon, bolla: int, pagante: ?int}>
     */
    private function periodi(array $bolle): ?array
    {
        usort($bolle, fn (array $a, array $b) => $a['data'] <=> $b['data']);

        $periodi = [];

        foreach ($bolle as $i => $bolla) {
            $dopo = $bolle[$i + 1] ?? null;

            if ($dopo && $dopo['data']->isSameDay($bolla['data']) && $dopo['cliente'] !== $bolla['cliente']) {
                return null;
            }

            // Stessa consegna scritta due volte (due articoli sulla stessa
            // bolla): un periodo solo.
            if ($dopo && $dopo['data']->isSameDay($bolla['data'])) {
                continue;
            }

            $periodi[] = [
                'cliente' => $bolla['cliente'],
                'dal' => $bolla['data']->copy()->startOfDay(),
                'al' => $dopo ? $dopo['data']->copy()->startOfDay() : null,
                'bolla' => $bolla['bolla'],
                'pagante' => $bolla['pagante'],
            ];
        }

        return $periodi;
    }

    /**
     * Cosa manca, cosa va chiuso diversamente e da quando vale il
     * posizionamento di adesso.
     *
     * La macchina non si sposta mai da qui: se il cliente di adesso e'
     * quello dell'ultima bolla, pero', l'inizio di quel periodo e' la data
     * della bolla, non quella della prima consegna di anni prima — senza
     * questa correzione una macchina mai spostata a mano si mangia tutta la
     * storia in mezzo (23/09/2026: in produzione il comando non trovava
     * niente da fare proprio sulle macchine che ne avevano piu' bisogno).
     * Se invece adesso e' da un altro, lo spostamento lo propone il sync e
     * qui ci si ferma prima.
     *
     * @param  array<int, array{cliente: string, dal: Carbon, al: ?Carbon, bolla: int, pagante: ?int}>  $periodi
     * @return array{nuovi: array<int, array<string, mixed>>, chiusure: array<int, array{riga: MachineUnitPlacement, nuova: Carbon}>, inizio: ?array{riga: MachineUnitPlacement, nuovo: Carbon}}
     */
    private function confronta(MachineUnit $macchina, array $periodi): array
    {
        $esistenti = $macchina->placements->sortBy('placed_at')->values();
        $attuale = $esistenti->last(fn (MachineUnitPlacement $p) => $p->removed_at === null);
        $ultimo = end($periodi) ?: null;

        // Da dove in poi la storia la racconta il posizionamento di adesso.
        $confine = $attuale?->placed_at->copy()->startOfDay();
        $inizio = null;

        if ($attuale && $ultimo && $attuale->customer_id === $ultimo['cliente'] && $confine->lt($ultimo['dal'])) {
            $confine = $ultimo['dal']->copy();
            $inizio = ['riga' => $attuale, 'nuovo' => $ultimo['dal']->copy()];
        }

        $nuovi = [];

        // Spostando in avanti la riga aperta si perderebbe il periodo che
        // raccontava: quello resta, chiuso quando comincia la consegna dopo.
        if ($inizio) {
            $primaConsegnaDopo = collect($periodi)
                ->pluck('dal')
                ->first(fn (Carbon $d) => $d->gt($attuale->placed_at->copy()->startOfDay()));

            if ($primaConsegnaDopo && ! $attuale->placed_at->copy()->startOfDay()->isSameDay($primaConsegnaDopo)) {
                $nuovi[] = [
                    'cliente' => $attuale->customer_id,
                    'dal' => $attuale->placed_at->copy()->startOfDay(),
                    'al' => $primaConsegnaDopo->copy(),
                    'bolla' => 0,
                    'pagante' => $attuale->eureka_billing_customer_code,
                ];
            }
        }

        // La riga aperta, se la spostiamo in avanti, e' quella dell'ultima
        // bolla: non vale piu' come "periodo gia' presente" per la consegna
        // di anni prima, che va riscritta come periodo chiuso.
        $confronto = $inizio ? $esistenti->reject(fn (MachineUnitPlacement $p) => $p->is($attuale)) : $esistenti;

        foreach ($periodi as $periodo) {
            $gia = $confronto->first(fn (MachineUnitPlacement $p) => $p->customer_id === $periodo['cliente']
                && $p->placed_at->isSameDay($periodo['dal']));

            if ($gia || ($confine && $periodo['dal']->gte($confine))) {
                continue;
            }

            // L'ultimo periodo resterebbe aperto: se la macchina adesso e'
            // altrove, si chiude quando comincia quello di adesso — due
            // posizionamenti aperti insieme non devono mai esistere.
            $nuovi[] = ['al' => $periodo['al'] ?? $confine] + $periodo;
        }

        // Lo stesso periodo puo' arrivare due volte: dalla riga aperta che
        // si sposta in avanti e dalla bolla che lo apriva.
        $nuovi = collect($nuovi)
            ->unique(fn (array $p) => $p['cliente'].'|'.$p['dal']->toDateString())
            ->values()
            ->all();

        $inizi = collect($periodi)->pluck('dal');
        $chiusure = [];

        foreach ($esistenti as $riga) {
            // Il posizionamento aperto resta aperto: al massimo comincia dopo.
            if ($attuale && $riga->is($attuale)) {
                continue;
            }

            $prossimo = $inizi->first(fn (Carbon $d) => $d->gt($riga->placed_at->copy()->startOfDay()));

            if ($prossimo && (! $riga->removed_at || ! $riga->removed_at->isSameDay($prossimo))) {
                $chiusure[] = ['riga' => $riga, 'nuova' => $prossimo->copy()->startOfDay()];
            }
        }

        return ['nuovi' => $nuovi, 'chiusure' => $chiusure, 'inizio' => $inizio];
    }

    private function data(mixed $valore): ?Carbon
    {
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($valore)->startOfDay(), null, false);
    }
}
