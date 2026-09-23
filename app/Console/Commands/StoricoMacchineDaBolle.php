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

            if ($lavoro['nuovi'] === [] && $lavoro['chiusure'] === []) {
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
            ['Matricola', 'Periodi da aggiungere', 'Chiusure da correggere'],
            collect($daFare)->map(fn (array $r) => [
                $r['macchina']->serial_number,
                collect($r['nuovi'])->map(fn (array $p) => $p['dal']->format('d/m/Y').' → '.DisplayName::titleCase($nomi[$p['cliente']] ?? '?'))->implode("\n") ?: '—',
                collect($r['chiusure'])->map(fn (array $c) => $c['riga']->placed_at->format('d/m/Y').': '
                    .($c['riga']->removed_at?->format('d/m/Y') ?? 'aperto').' → '.$c['nuova']->format('d/m/Y'))->implode("\n") ?: '—',
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
     * Cosa manca e cosa va chiuso diversamente, senza mai toccare il
     * posizionamento aperto piu' recente (quello dice dov'e' la macchina
     * adesso, e puo' venire da uno "Sposta" fatto a mano).
     *
     * @param  array<int, array{cliente: string, dal: Carbon, al: ?Carbon, bolla: int, pagante: ?int}>  $periodi
     * @return array{nuovi: array<int, array<string, mixed>>, chiusure: array<int, array{riga: MachineUnitPlacement, nuova: Carbon}>}
     */
    private function confronta(MachineUnit $macchina, array $periodi): array
    {
        $esistenti = $macchina->placements->sortBy('placed_at')->values();
        $attuale = $esistenti->last(fn (MachineUnitPlacement $p) => $p->removed_at === null);

        $nuovi = [];

        foreach ($periodi as $periodo) {
            $gia = $esistenti->first(fn (MachineUnitPlacement $p) => $p->customer_id === $periodo['cliente']
                && $p->placed_at->isSameDay($periodo['dal']));

            // Il periodo aperto attuale vince su quello che dicono le bolle:
            // se la macchina e' stata spostata a mano dopo l'ultima bolla,
            // la storia nuova si ferma li'.
            if ($gia || ($attuale && $periodo['dal']->gte($attuale->placed_at->copy()->startOfDay()))) {
                continue;
            }

            $nuovi[] = $periodo;
        }

        $inizi = collect($periodi)->pluck('dal');
        $chiusure = [];

        foreach ($esistenti as $riga) {
            // Il posizionamento aperto piu' recente resta aperto.
            if ($attuale && $riga->is($attuale)) {
                continue;
            }

            $prossimo = $inizi->first(fn (Carbon $d) => $d->gt($riga->placed_at->copy()->startOfDay()));

            if ($prossimo && (! $riga->removed_at || ! $riga->removed_at->isSameDay($prossimo))) {
                $chiusure[] = ['riga' => $riga, 'nuova' => $prossimo->copy()->startOfDay()];
            }
        }

        return ['nuovi' => $nuovi, 'chiusure' => $chiusure];
    }

    private function data(mixed $valore): ?Carbon
    {
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($valore)->startOfDay(), null, false);
    }
}
