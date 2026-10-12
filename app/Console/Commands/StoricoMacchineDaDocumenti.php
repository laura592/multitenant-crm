<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MachineUnitPlacement;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Gestionale\DocumentiMacchina;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo storico dei posizionamenti ricostruito dai documenti di Eureka.
 *
 * Prende il posto di macchine:storico-da-bolle, che leggeva
 * /show/q/art_installati: quell'elenco riporta solo la consegna CORRENTE di
 * ogni matricola, quindi i passaggi di mezzo non li vede. Sulla matricola
 * 3400000282946 dava "Marcuzzo dal 15/07/2025" mentre i documenti
 * raccontano Hotel Vidi a marzo 2026, Marcuzzo a maggio, il rientro in casa
 * il 25/09 e il Neps (per conto di Dersut) il 09/10.
 *
 * La fonte e' /articoli/cerca-in-righe sulla matricola — vedi
 * App\Support\Gestionale\DocumentiMacchina e
 * docs/gestionale-eureka/macchinari.md.
 *
 * Oltre allo storico il comando risponde alla domanda dell'ufficio
 * (12/10/2026): "così puoi anche vedere le macchine che sono nel posto
 * sbagliato, nel senso che sono da un cliente ma manca il DDT o viceversa
 * ho il DDT ma non sono segnate al cliente". Sono le due liste in fondo.
 *
 * Non cancella mai un posizionamento, non tocca chi paga e non sposta la
 * macchina: il cliente attuale lo cambia una persona dalla schermata di
 * conferma del sync.
 */
class StoricoMacchineDaDocumenti extends Command
{
    protected $signature = 'macchine:storico-da-documenti
                            {--tenant=alex : slug del tenant}
                            {--matricola= : una sola matricola, invece di tutte}
                            {--dry-run : mostra prima/dopo senza scrivere}
                            {--limite=0 : ferma la lettura alle prime N macchine (per provare)}';

    protected $description = 'Ricostruisce lo storico dei posizionamenti dai documenti Eureka e segnala le macchine nel posto sbagliato';

    /** @var array<int, string> */
    private array $righeAnomalie = [];

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $macchine = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('serial_number')
            ->when($this->option('matricola'), fn ($q, $m) => $q->where('serial_number', $m))
            ->with(['placements' => fn ($q) => $q->whereNull('deleted_at')->orderBy('placed_at')])
            ->orderBy('serial_number')
            ->get()
            ->filter(fn (MachineUnit $m) => DocumentiMacchina::cercabile((string) $m->serial_number))
            ->values();

        if ((int) $this->option('limite') > 0) {
            $macchine = $macchine->take((int) $this->option('limite'))->values();
        }

        if ($macchine->isEmpty()) {
            $this->warn('Nessuna macchina con una matricola cercabile su Eureka.');

            return self::SUCCESS;
        }

        $this->info('Cerco i documenti di '.$macchine->count().' matricole su Eureka: una chiamata per matricola.');

        $esito = DocumentiMacchina::perMatricole($tenant, $macchine->pluck('serial_number'));

        if ($esito['falliti'] !== []) {
            $this->warn('Eureka non ha risposto per '.count($esito['falliti']).' matricole: '
                .implode(', ', array_slice($esito['falliti'], 0, 8)).' — quelle restano fuori dal confronto.');
        }

        $clienti = Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->get(['id', 'company_name', 'gestionale_code']);

        $perCodice = $clienti->whereNotNull('gestionale_code')->keyBy(fn (Customer $c) => (int) $c->gestionale_code);
        $perNome = $clienti->keyBy(fn (Customer $c) => self::normalizza($c->company_name));
        $nomi = $clienti->pluck('company_name', 'id');

        $client = new EurekaClient($tenant);
        $daFare = [];
        $senzaDocumenti = 0;
        $alClienteSenzaDocumento = [];
        $documentoAltrove = [];

        foreach ($macchine as $macchina) {
            $movimenti = $esito['movimenti'][MachineUnit::chiaveMatricola($macchina->serial_number)] ?? [];

            if ($movimenti === []) {
                $senzaDocumenti++;

                continue;
            }

            $movimenti = $this->conDestinazione($client, $macchina, $movimenti, $perCodice, $perNome, $tenant);
            $periodi = $this->periodi($movimenti);

            if ($periodi === []) {
                continue;
            }

            $this->anomalie($macchina, $periodi, $nomi, $alClienteSenzaDocumento, $documentoAltrove);

            $lavoro = $this->confronta($macchina, $periodi);

            if ($lavoro['nuovi'] !== [] || $lavoro['chiusure'] !== []) {
                $daFare[] = ['macchina' => $macchina] + $lavoro;
            }
        }

        $this->line('');
        $this->line('Macchine senza nessun documento su Eureka: '.$senzaDocumenti.' su '.$macchine->count());

        $this->mostraAnomalie($alClienteSenzaDocumento, $documentoAltrove);

        if ($daFare === []) {
            $this->info('Storico: i documenti non aggiungono niente a quello che c\'e\' nel CRM.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('STORICO DA COMPLETARE');
        $this->table(
            ['Matricola', 'Periodi da aggiungere', 'Chiusure da correggere'],
            collect($daFare)->map(fn (array $r) => [
                $r['macchina']->serial_number,
                collect($r['nuovi'])->map(fn (array $p) => $p['dal']->format('d/m/Y')
                    .' → '.$this->etichettaCliente($p['cliente'], $nomi)
                    .' ('.$p['documento'].')')->implode("\n") ?: '—',
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
                    'placed_at' => $periodo['dal'],
                    'removed_at' => $periodo['al'],
                    'notes' => 'Ricostruito dai documenti Eureka: '.$periodo['documento'],
                ]);
            }
        }

        $this->info('Fatto: storico completato su '.count($daFare).' macchine.');

        return self::SUCCESS;
    }

    /**
     * Il cliente di ogni movimento: l'intestatario, oppure la destinazione
     * scritta sul documento quando chi paga non e' chi riceve (bolla
     * intestata a Dersut, macchina all'Hotel Neps). La destinazione si legge
     * solo sui tipi consentiti (BC, FB, SL) e solo sugli ultimi tre
     * documenti, per non chiedere a Eureka tre chiamate per macchina su
     * ottocento macchine.
     *
     * Intestatario noi stessi = la macchina e' rientrata in casa.
     *
     * @param  array<int, array<string, mixed>>  $movimenti
     * @param  Collection<int, Customer>  $perCodice
     * @param  Collection<string, Customer>  $perNome
     * @return array<int, array<string, mixed>>
     */
    private function conDestinazione(
        EurekaClient $client,
        MachineUnit $macchina,
        array $movimenti,
        Collection $perCodice,
        Collection $perNome,
        Tenant $tenant,
    ): array {
        $ultimi = array_slice(array_keys($movimenti), -3);

        foreach ($movimenti as $i => $movimento) {
            $cliente = $perCodice->get((int) $movimento['codice_cliente']);

            $movimenti[$i]['in_casa'] = $this->siamoNoi((string) $movimento['ragione_sociale'], $tenant);
            $movimenti[$i]['cliente'] = $movimenti[$i]['in_casa'] ? null : $cliente?->id;
            $movimenti[$i]['documento'] = trim($movimento['tipo'].' n.'.$movimento['numero'].' del '.$movimento['data']->format('d/m/Y'));

            if (! in_array($i, $ultimi, true) || $movimenti[$i]['in_casa']) {
                continue;
            }

            $destinazione = DocumentiMacchina::destinazione($client, (int) $movimento['id_doc']);

            if ($destinazione === null) {
                continue;
            }

            $altro = $perNome->get(self::normalizza($destinazione));

            if ($altro && $altro->id !== $movimenti[$i]['cliente']) {
                $movimenti[$i]['cliente'] = $altro->id;
                $movimenti[$i]['documento'] .= ', destinazione '.DisplayName::titleCase($altro->company_name);
            }
        }

        return $movimenti;
    }

    /**
     * I periodi: ogni movimento apre un periodo presso il suo cliente, e lo
     * chiude il movimento successivo che porta la macchina altrove. Due
     * documenti di fila dallo stesso cliente (bolla + fattura della stessa
     * consegna) sono un periodo solo.
     *
     * @param  array<int, array<string, mixed>>  $movimenti
     * @return array<int, array{cliente: ?string, dal: Carbon, al: ?Carbon, documento: string, in_casa: bool}>
     */
    private function periodi(array $movimenti): array
    {
        $periodi = [];

        foreach ($movimenti as $movimento) {
            $ultimo = end($periodi) ?: null;

            if ($ultimo && $ultimo['cliente'] === $movimento['cliente'] && $ultimo['in_casa'] === $movimento['in_casa']) {
                continue;
            }

            if ($ultimo) {
                $periodi[count($periodi) - 1]['al'] = $movimento['data']->copy();
            }

            $periodi[] = [
                'cliente' => $movimento['cliente'],
                'dal' => $movimento['data']->copy(),
                'al' => null,
                'documento' => $movimento['documento'],
                'in_casa' => (bool) $movimento['in_casa'],
            ];
        }

        return $periodi;
    }

    /**
     * Le due liste che l'ufficio ha chiesto: dove la macchina sta senza che
     * nessun documento lo dica, e dove i documenti la mandano senza che il
     * CRM l'abbia segnata.
     *
     * @param  array<int, array<string, mixed>>  $periodi
     * @param  Collection<string, string>  $nomi
     * @param  array<int, array<int, string>>  $alClienteSenzaDocumento
     * @param  array<int, array<int, string>>  $documentoAltrove
     */
    private function anomalie(
        MachineUnit $macchina,
        array $periodi,
        Collection $nomi,
        array &$alClienteSenzaDocumento,
        array &$documentoAltrove,
    ): void {
        $attuale = $macchina->placements->last(fn (MachineUnitPlacement $p) => $p->removed_at === null);
        $ultimo = end($periodi) ?: null;

        if (! $ultimo) {
            return;
        }

        $clientiNeiDocumenti = collect($periodi)->pluck('cliente')->filter()->unique();

        // Il CRM la da' da un cliente che nei documenti non compare mai.
        if ($attuale?->customer_id && ! $clientiNeiDocumenti->contains($attuale->customer_id)) {
            $alClienteSenzaDocumento[] = [
                $macchina->serial_number,
                DisplayName::titleCase($nomi[$attuale->customer_id] ?? '?'),
                $attuale->placed_at->format('d/m/Y'),
                $ultimo['documento'],
            ];
        }

        // I documenti la mandano da un cliente (o in casa) e il CRM e'
        // rimasto indietro.
        $doveDicono = $ultimo['in_casa'] ? null : $ultimo['cliente'];

        if ($doveDicono !== ($attuale?->customer_id)) {
            $documentoAltrove[] = [
                $macchina->serial_number,
                $attuale?->customer_id ? DisplayName::titleCase($nomi[$attuale->customer_id] ?? '?') : 'magazzino',
                $doveDicono ? DisplayName::titleCase($nomi[$doveDicono] ?? '?') : 'rientrata in casa',
                $ultimo['documento'],
            ];
        }
    }

    /**
     * @param  array<int, array<int, string>>  $alClienteSenzaDocumento
     * @param  array<int, array<int, string>>  $documentoAltrove
     */
    private function mostraAnomalie(array $alClienteSenzaDocumento, array $documentoAltrove): void
    {
        $this->line('');
        $this->line('DA UN CLIENTE, MA NESSUN DOCUMENTO LO DICE ('.count($alClienteSenzaDocumento).')');

        if ($alClienteSenzaDocumento === []) {
            $this->line('  nessuna');
        } else {
            $this->table(['Matricola', 'Nel CRM e\' da', 'Dal', 'Ultimo documento'], $alClienteSenzaDocumento);
        }

        $this->line('');
        $this->line('I DOCUMENTI LA DANNO ALTROVE ('.count($documentoAltrove).')');

        if ($documentoAltrove === []) {
            $this->line('  nessuna');
        } else {
            $this->table(['Matricola', 'Nel CRM', 'Secondo i documenti', 'Ultimo documento'], $documentoAltrove);
        }
    }

    /**
     * Cosa manca nello storico e cosa va chiuso diversamente.
     *
     * Il posizionamento aperto non si tocca: se i documenti dicono che la
     * macchina e' altrove lo si segnala nelle anomalie e decide una persona.
     *
     * @param  array<int, array<string, mixed>>  $periodi
     * @return array{nuovi: array<int, array<string, mixed>>, chiusure: array<int, array{riga: MachineUnitPlacement, nuova: Carbon}>}
     */
    private function confronta(MachineUnit $macchina, array $periodi): array
    {
        $esistenti = $macchina->placements->sortBy('placed_at')->values();
        $attuale = $esistenti->last(fn (MachineUnitPlacement $p) => $p->removed_at === null);
        $confine = $attuale?->placed_at->copy()->startOfDay();
        $nuovi = [];

        foreach ($periodi as $periodo) {
            // In casa non e' un posizionamento da scrivere: e' l'assenza di
            // uno, e la chiusura del periodo precedente lo racconta gia'.
            if ($periodo['in_casa'] || $periodo['cliente'] === null) {
                continue;
            }

            $gia = $esistenti->first(fn (MachineUnitPlacement $p) => $p->customer_id === $periodo['cliente']
                && $p->placed_at->copy()->startOfDay()->isSameDay($periodo['dal']));

            // Dal posizionamento aperto in avanti la storia e' quella del
            // CRM: i documenti piu' recenti di cosi' sono spostamenti da
            // confermare a mano, non storia da riscrivere.
            if ($gia || ($confine && $periodo['dal']->gte($confine))) {
                continue;
            }

            $nuovi[] = [
                'cliente' => $periodo['cliente'],
                'dal' => $periodo['dal']->copy(),
                'al' => $periodo['al']?->copy() ?? $confine,
                'documento' => $periodo['documento'],
            ];
        }

        $inizi = collect($periodi)->pluck('dal');
        $chiusure = [];

        foreach ($esistenti as $riga) {
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

    /** @param  Collection<string, string>  $nomi */
    private function etichettaCliente(?string $customerId, Collection $nomi): string
    {
        return $customerId ? DisplayName::titleCase($nomi[$customerId] ?? '?') : 'magazzino';
    }

    /**
     * Il documento e' intestato a noi: la macchina e' rientrata in casa
     * (il BCF n.246 del 25/09/2026 e' intestato ad "ALEX SRL SOCIETA'
     * UNIPERSONALE").
     */
    private function siamoNoi(string $ragioneSociale, Tenant $tenant): bool
    {
        $nostri = array_filter([$tenant->legal_name, $tenant->name]);

        foreach ($nostri as $nostro) {
            $nostro = self::normalizza((string) $nostro);
            $loro = self::normalizza($ragioneSociale);

            if ($nostro !== '' && (str_starts_with($loro, $nostro) || str_starts_with($nostro, $loro))) {
                return true;
            }
        }

        return false;
    }

    /** Ragioni sociali confrontabili: niente forma societaria, niente punteggiatura. */
    private static function normalizza(?string $nome): string
    {
        $nome = mb_strtolower(trim((string) $nome));
        $nome = preg_replace('/\b(s\.?r\.?l\.?|s\.?p\.?a\.?|s\.?a\.?s\.?|s\.?n\.?c\.?|societa\'?|unipersonale|di|&|c\.)\b/u', ' ', $nome);
        $nome = preg_replace('/[^a-z0-9]+/u', ' ', (string) $nome);

        return trim(preg_replace('/\s+/', ' ', (string) $nome));
    }
}
