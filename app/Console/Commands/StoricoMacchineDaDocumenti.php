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

        if (($esito['guasto'] ?? null) !== null) {
            $this->error('Eureka non risponde: '.$esito['guasto']);
            $this->line('Niente e\' stato letto ne\' scritto. Riprova piu\' tardi.');

            return self::FAILURE;
        }

        if ($esito['falliti'] !== []) {
            $this->warn('Eureka non ha risposto per '.count($esito['falliti']).' matricole: '
                .implode(', ', array_slice($esito['falliti'], 0, 8)).' — quelle restano fuori dal confronto.');
        }

        $clienti = Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->get(['id', 'company_name', 'gestionale_code', 'billing_customer_id']);

        $perCodice = $clienti->whereNotNull('gestionale_code')->keyBy(fn (Customer $c) => (int) $c->gestionale_code);
        $perNome = $clienti->keyBy(fn (Customer $c) => self::normalizza($c->company_name));
        $nomi = $clienti->pluck('company_name', 'id');

        // Chi paga per chi, e il nome normalizzato di ognuno: servono a NON
        // chiamare anomalia due cose che non lo sono — la fattura intestata
        // al pagante (Dersut compra la macchina, il cliente la usa) e le due
        // schede dello stesso cliente (906 "Hotel Neps" e 907
        // "Hotel-Ristorante Neps").
        $paganti = $clienti->pluck('billing_customer_id', 'id');
        $nomiNormalizzati = $clienti->mapWithKeys(fn (Customer $c) => [$c->id => self::normalizza($c->company_name)]);

        $client = new EurekaClient($tenant);
        $daFare = [];
        $senzaDocumenti = 0;
        $alClienteSenzaDocumento = [];
        $documentoAltrove = [];
        $doveNonSappiamo = [];

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

            $this->anomalie(
                $macchina,
                $periodi,
                $nomi,
                compatibile: fn (?string $a, ?string $b): bool => $this->compatibili($a, $b, $macchina, $paganti, $nomiNormalizzati),
                alClienteSenzaDocumento: $alClienteSenzaDocumento,
                documentoAltrove: $documentoAltrove,
                doveNonSappiamo: $doveNonSappiamo,
            );

            $lavoro = $this->confronta($macchina, $periodi);

            if ($lavoro['nuovi'] !== [] || $lavoro['chiusure'] !== []) {
                $daFare[] = ['macchina' => $macchina] + $lavoro;
            }
        }

        $this->line('');
        $this->line('Macchine senza nessun documento su Eureka: '.$senzaDocumenti.' su '.$macchine->count());

        $this->mostraAnomalie($alClienteSenzaDocumento, $documentoAltrove, $doveNonSappiamo);

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
     * intestata a Dersut, macchina all'Hotel Neps).
     *
     * La destinazione costa una chiamata e si legge solo dove serve: sul
     * documento piu' recente, e solo se il suo intestatario non e' gia' il
     * cliente dove il CRM tiene la macchina. Su ottocento macchine leggerle
     * tutte sarebbero duemilacinquecento chiamate in fila, e Eureka un
     * carico cosi' l'ha gia' sentito (06/08/2026).
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
        foreach ($movimenti as $i => $movimento) {
            $cliente = $perCodice->get((int) $movimento['codice_cliente']);

            $movimenti[$i]['in_casa'] = $this->siamoNoi((string) $movimento['ragione_sociale'], $tenant);
            $movimenti[$i]['cliente'] = $movimenti[$i]['in_casa'] ? null : $cliente?->id;
            $movimenti[$i]['documento'] = trim($movimento['tipo'].' n.'.$movimento['numero'].' del '.$movimento['data']->format('d/m/Y'));
        }

        $ultimo = array_key_last($movimenti);
        $attuale = $macchina->placements->last(fn (MachineUnitPlacement $p) => $p->removed_at === null)?->customer_id;

        if ($ultimo === null
            || $movimenti[$ultimo]['in_casa']
            || $movimenti[$ultimo]['cliente'] === $attuale) {
            return $movimenti;
        }

        $destinazione = DocumentiMacchina::destinazione(
            $client,
            (int) $movimenti[$ultimo]['id_doc'],
            (string) $movimenti[$ultimo]['tipo'],
        );
        $altro = $destinazione !== null ? $perNome->get(self::normalizza($destinazione)) : null;

        if ($altro && $altro->id !== $movimenti[$ultimo]['cliente']) {
            $movimenti[$ultimo]['cliente'] = $altro->id;
            $movimenti[$ultimo]['documento'] .= ', destinazione '.DisplayName::titleCase($altro->company_name);
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
     * Le liste che l'ufficio ha chiesto: "le macchine che sono nel posto
     * sbagliato, nel senso che sono da un cliente ma manca il DDT o
     * viceversa ho il DDT ma non sono segnate al cliente" (12/10/2026).
     *
     * Tre liste e non due, perche' i casi sono tre e hanno urgenze diverse:
     * il CRM non sa dov'e' (nessun posizionamento aperto, ma un documento
     * dice a chi e' andata), il CRM la da' da un cliente che i documenti non
     * nominano, i documenti la danno da un altro cliente.
     *
     * @param  array<int, array<string, mixed>>  $periodi
     * @param  Collection<string, string>  $nomi
     * @param  \Closure(?string, ?string): bool  $compatibile
     * @param  array<int, array<int, string>>  $alClienteSenzaDocumento
     * @param  array<int, array<int, string>>  $documentoAltrove
     * @param  array<int, array<int, string>>  $doveNonSappiamo
     */
    private function anomalie(
        MachineUnit $macchina,
        array $periodi,
        Collection $nomi,
        \Closure $compatibile,
        array &$alClienteSenzaDocumento,
        array &$documentoAltrove,
        array &$doveNonSappiamo,
    ): void {
        $attuale = $macchina->placements->last(fn (MachineUnitPlacement $p) => $p->removed_at === null);
        $ultimo = end($periodi) ?: null;

        if (! $ultimo) {
            return;
        }

        $doveDicono = $ultimo['in_casa'] ? null : $ultimo['cliente'];

        // Nessun posizionamento aperto: il CRM non sa dov'e'. Se un documento
        // dice a chi e' andata, e' una macchina da collocare.
        if (! $attuale) {
            if ($doveDicono) {
                $doveNonSappiamo[] = [
                    $macchina->serial_number,
                    DisplayName::titleCase($nomi[$doveDicono] ?? '?'),
                    $ultimo['dal']->format('d/m/Y'),
                    $ultimo['documento'],
                ];
            }

            return;
        }

        // Il CRM la da' da un cliente che in nessun documento compare, ne'
        // lui ne' chi paga per lui.
        $nominato = collect($periodi)
            ->pluck('cliente')
            ->filter()
            ->unique()
            ->contains(fn (?string $id) => $compatibile($id, $attuale->customer_id));

        if ($attuale->customer_id && ! $nominato) {
            $alClienteSenzaDocumento[] = [
                $macchina->serial_number,
                DisplayName::titleCase($nomi[$attuale->customer_id] ?? '?'),
                $attuale->placed_at->format('d/m/Y'),
                $ultimo['documento'],
            ];
        }

        // I documenti la mandano da un altro cliente e il CRM e' rimasto
        // indietro. L'ultimo documento intestato a noi non basta a dire che
        // e' rientrata (puo' essere una riparazione fatta in casa): si segna
        // solo come documento intestato a noi, e decide una persona.
        if (! $compatibile($doveDicono, $attuale->customer_id)) {
            $documentoAltrove[] = [
                $macchina->serial_number,
                $attuale->customer_id ? DisplayName::titleCase($nomi[$attuale->customer_id] ?? '?') : 'magazzino',
                $ultimo['in_casa']
                    ? 'documento intestato a noi'
                    : DisplayName::titleCase($nomi[$doveDicono] ?? '?'),
                $ultimo['documento'],
            ];
        }
    }

    /**
     * Lo stesso posto, detto in due modi: lo stesso cliente, il pagante del
     * cliente (o della macchina), oppure due schede dello stesso nome.
     *
     * @param  Collection<string, ?string>  $paganti
     * @param  Collection<string, string>  $nomiNormalizzati
     */
    private function compatibili(
        ?string $uno,
        ?string $altro,
        MachineUnit $macchina,
        Collection $paganti,
        Collection $nomiNormalizzati,
    ): bool {
        if ($uno === $altro) {
            return true;
        }

        if ($uno === null || $altro === null) {
            // Un documento intestato a noi contro un cliente: diverso.
            // Il pagante della macchina invece vale come riscontro.
            return false;
        }

        if ($paganti->get($uno) === $altro || $paganti->get($altro) === $uno) {
            return true;
        }

        if ($macchina->billing_customer_id && in_array($macchina->billing_customer_id, [$uno, $altro], true)) {
            return true;
        }

        $nomeUno = $nomiNormalizzati->get($uno);
        $nomeAltro = $nomiNormalizzati->get($altro);

        return $nomeUno !== null && $nomeUno !== '' && $nomeUno === $nomeAltro;
    }

    /**
     * @param  array<int, array<int, string>>  $alClienteSenzaDocumento
     * @param  array<int, array<int, string>>  $documentoAltrove
     * @param  array<int, array<int, string>>  $doveNonSappiamo
     */
    private function mostraAnomalie(array $alClienteSenzaDocumento, array $documentoAltrove, array $doveNonSappiamo): void
    {
        $this->line('');
        $this->line('IL CRM NON SA DOVE SONO, I DOCUMENTI SI\' ('.count($doveNonSappiamo).')');

        if ($doveNonSappiamo === []) {
            $this->line('  nessuna');
        } else {
            $this->table(['Matricola', 'Secondo i documenti e\' da', 'Dal', 'Documento'], $doveNonSappiamo);
        }

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
