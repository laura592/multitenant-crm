<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rimette il numero di vie sui lavaggi che ce l'hanno perso per strada
 * (24/09/2026, terza fonte aggiunta il 27/11/2026).
 *
 * Su 989 lavaggi, 293 non hanno il numero di vie — ed è il campo su cui si
 * fattura. Tre sorgenti possibili, nell'ordine:
 *
 * 1. la descrizione, dove il tecnico l'ha scritto a mano: "1 Via",
 *    "6 Vie + Chiusura", "4 Vie + Acqua";
 * 2. le vie del piano collegato (`lines_count`), che dice quante ne ha
 *    quell'impianto per quella bevanda;
 * 3. lo storico dello stesso impianto per la stessa bevanda, quando tutti i
 *    lavaggi già valorizzati concordano su un numero solo.
 *
 * Non inventa: se nessuna delle tre dice niente, il lavaggio resta com'è e
 * finisce nell'elenco di quelli da guardare a mano.
 *
 * Strade provate e scartate, perché nessuno le riprovi (27/11/2026). Le
 * righe di materiale del rapportino sembrano la fonte perfetta — sono quelle
 * che vanno in fattura — ma non lo sono: "LAVAGGIO 2 VIE" (codice LAV2) è
 * una tariffa base che copre sia 1 che 2 vie, e "ULTERIORE VIA LAVATA"
 * (ULTVIA) conta le vie in più di tutta la scheda, non di un impianto. La
 * formula 2+ULTVIA indovina il 49% dei lavaggi già valorizzati, e su
 * Campeggio Europa arriva a 16 vie per un impianto d'acqua. Scartate anche
 * le vie scritte nell'articolo a catalogo della macchina ("IMPIANTO ALLA
 * SPINA 4 VIE": 57%, perché è il totale dell'impianto e non la quota della
 * singola bevanda) e lo storico del cliente invece dell'impianto (77%).
 */
class VieMancantiNeiLavaggi extends Command
{
    protected $signature = 'lavaggi:vie-mancanti
                            {--tenant=alex : slug del tenant}
                            {--dal= : solo i lavaggi da questa data (YYYY-MM-DD)}
                            {--solo-descrizione : non usare piano e storico, solo le vie scritte a mano}
                            {--tutti : elenca tutte le deduzioni, non solo le prime 25}
                            {--dry-run : mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Rimette il numero di vie sui lavaggi che non ce l\'hanno, leggendolo dalla descrizione, dal piano o dallo storico dell\'impianto';

    /**
     * Vie già note, per impianto e bevanda: "idImpianto|bevanda" => quante
     * volte si è visto ciascun numero di vie.
     *
     * @var array<string, array<int, int>>
     */
    private array $storicoVie = [];

    /**
     * Quanti lavaggi ha ciascuna visita: "idCliente|AAAA-MM-GG" => quanti.
     * Serve solo a capire se il numero scritto in descrizione riguarda
     * questo impianto o tutta la giornata (vedi vieScritteAMano()).
     *
     * @var array<string, int>
     */
    private array $lavaggiPerVisita = [];

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $this->storicoVie = $this->leggiStoricoVie($tenant);
        $this->lavaggiPerVisita = $this->contaLavaggiPerVisita($tenant);

        $lavaggi = Lavaggio::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereNull('lines_washed')->orWhere('lines_washed', 0))
            ->when($this->option('dal'), fn ($q, $dal) => $q->whereDate('data', '>=', $dal))
            ->with(['customer', 'machineUnit', 'maintenanceSchedule.machineUnit'])
            ->orderBy('data')
            ->get();

        if ($lavaggi->isEmpty()) {
            $this->info('Tutti i lavaggi hanno il numero di vie.');

            return self::SUCCESS;
        }

        $daScrivere = [];
        $senzaFonte = [];

        foreach ($lavaggi as $lavaggio) {
            $deduzione = $this->deduci($lavaggio);

            if ($deduzione === null) {
                $senzaFonte[] = $lavaggio;

                continue;
            }

            $daScrivere[] = $deduzione + ['lavaggio' => $lavaggio];
        }

        $quante = $this->option('tutti') ? count($daScrivere) : 25;

        if ($daScrivere !== []) {
            $this->table(
                ['Data', 'Cliente', 'Impianto', 'Vie', 'Da dove', 'Perche\''],
                collect($daScrivere)->take($quante)->map(fn (array $r) => [
                    $r['lavaggio']->data->format('d/m/Y'),
                    mb_substr(DisplayName::titleCase($this->cliente($r['lavaggio'])?->company_name) ?? '—', 0, 26),
                    $this->matricola($r['lavaggio']),
                    $r['vie'],
                    $r['fonte'],
                    $r['perche'],
                ])->all(),
            );

            if (count($daScrivere) > $quante) {
                $this->line('  …e altri '.(count($daScrivere) - $quante).' (--tutti per vederli).');
            }
        }

        $this->info('Da sistemare: '.count($daScrivere).'.');

        foreach (collect($daScrivere)->countBy('fonte')->sortDesc() as $fonte => $quanti) {
            $this->line(sprintf('  %-18s %d', $fonte, $quanti));
        }

        $this->line('Senza niente da cui ricavarle: '.count($senzaFonte).'.');

        foreach (collect($senzaFonte)->countBy(fn (Lavaggio $l) => $this->percheNiente($l))->sortDesc() as $motivo => $quanti) {
            $this->line(sprintf('  %-34s %d', $motivo, $quanti));
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if ($daScrivere === [] || ! $this->confirm('Scrivo le vie su '.count($daScrivere).' lavaggi?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($daScrivere as $r) {
            $r['lavaggio']->update(['lines_washed' => $r['vie']]);
        }

        $this->info('Fatto: '.count($daScrivere).' lavaggi con le vie al loro posto.');
        $this->line('I '.count($senzaFonte).' rimasti si guardano dal rapportino, uno per uno.');

        return self::SUCCESS;
    }

    /**
     * Le tre fonti in ordine di precedenza, e per ognuna la frase che spiega
     * da dove salta fuori il numero: su un campo che finisce in fattura,
     * "il comando ha scritto 4" senza il perché non è verificabile da nessuno.
     *
     * @return array{vie: int, fonte: string, perche: string}|null
     */
    private function deduci(Lavaggio $lavaggio): ?array
    {
        if ($vie = $this->vieScritteAMano($lavaggio)) {
            return [
                'vie' => $vie,
                'fonte' => 'descrizione',
                'perche' => 'scritto a mano: "'.$lavaggio->descrizione.'"',
            ];
        }

        if ($this->option('solo-descrizione')) {
            return null;
        }

        if ($vie = $this->piano($lavaggio)?->lines_count ?: null) {
            return [
                'vie' => (int) $vie,
                'fonte' => 'piano',
                'perche' => 'il piano dichiara '.$vie.' vie',
            ];
        }

        return $this->vieDalloStorico($lavaggio);
    }

    /**
     * Il numero scritto in descrizione vale solo se quel giorno il cliente ha
     * un lavaggio solo (27/11/2026).
     *
     * Villa Gentile, 19/04/2023: le tre righe di quel giorno dicono tutte
     * "10 Vie", ma le vie vere sono 5 di birra, 4 di vino e 1 di selz. Il
     * tecnico scrive il totale della visita, non la quota del singolo
     * impianto, e prenderlo alla lettera fatturerebbe 30 vie invece di 10.
     * Sui lavaggi già valorizzati che hanno un numero in descrizione il
     * numero azzecca il 91% delle volte quando la visita è una riga sola e
     * il 28% quando sono più di una: sotto quella soglia non si scrive.
     */
    private function vieScritteAMano(Lavaggio $lavaggio): ?int
    {
        $vie = self::vieDallaDescrizione($lavaggio->descrizione);

        if ($vie === null) {
            return null;
        }

        return $this->lavaggiNellaVisita($lavaggio) === 1 ? $vie : null;
    }

    /**
     * L'ultima spiaggia: le vie che quell'impianto ha sempre avuto per quella
     * bevanda (27/11/2026).
     *
     * Camping Marina 2000, IMP-ACQUA-009: tre lavaggi valorizzati, tutti e
     * tre da 4 vie, e altri sei della stessa macchina senza niente. Se lo
     * storico è unanime il numero non è un'invenzione, è una constatazione.
     *
     * Due vincoli che sembrano pignoli e non lo sono:
     *
     * - la bevanda è obbligatoria. Sullo stesso impianto spina birra, vino e
     *   selz hanno vie diverse: mischiarle vuol dire fatturare le vie del
     *   vino al prezzo della birra. Senza bevanda sul piano non si deduce.
     * - lo storico deve dire un numero solo. Basta un lavaggio parziale in
     *   mezzo (su IMP-SPINA-022 ci sono sia 1 che 3 vie) e il caso torna a
     *   mano: è lì che le deduzioni sbagliate si annidano.
     *
     * @return array{vie: int, fonte: string, perche: string}|null
     */
    private function vieDalloStorico(Lavaggio $lavaggio): ?array
    {
        $visti = $this->storicoVie[$this->chiaveImpianto($lavaggio) ?? ''] ?? [];

        if (count($visti) !== 1) {
            return null;
        }

        $vie = (int) array_key_first($visti);

        return [
            'vie' => $vie,
            'fonte' => 'storico impianto',
            'perche' => sprintf(
                'su %s (%s) %s da %s',
                $this->matricola($lavaggio),
                (string) $this->piano($lavaggio)?->beverage_type,
                $visti[$vie] === 1 ? 'l\'unico altro lavaggio e\'' : 'gli altri '.$visti[$vie].' lavaggi sono tutti',
                $vie === 1 ? '1 via' : $vie.' vie',
            ),
        ];
    }

    /**
     * Perché questo lavaggio resta a mano. Non è decorazione: dice a chi lo
     * apre se manca un collegamento da sistemare (impianto o bevanda) o se
     * davvero non c'è niente da leggere fuori dal rapportino di carta.
     */
    private function percheNiente(Lavaggio $lavaggio): string
    {
        if (! ($lavaggio->machine_unit_id ?: $this->piano($lavaggio)?->machine_unit_id)) {
            return 'nessun impianto collegato';
        }

        if (! $this->piano($lavaggio)?->beverage_type) {
            return 'piano senza bevanda';
        }

        $visti = $this->storicoVie[$this->chiaveImpianto($lavaggio) ?? ''] ?? [];

        if ($visti === []) {
            return 'primo lavaggio di questo impianto';
        }

        return 'storico discorde ('.implode('/', array_keys($visti)).' vie)';
    }

    /**
     * Le vie già viste su ogni impianto, bevanda per bevanda. Si legge in una
     * query sola invece di una per lavaggio: il comando gira su tutto lo
     * storico e sono quasi mille righe.
     *
     * L'impianto è quello scritto sul lavaggio, o quello del piano quando il
     * lavaggio non lo ripete (vedi Lavaggio::machineLabel()).
     *
     * @return array<string, array<int, int>>
     */
    private function leggiStoricoVie(Tenant $tenant): array
    {
        $righe = DB::table('lavaggi as l')
            ->join('maintenance_schedules as ms', 'ms.id', '=', 'l.maintenance_schedule_id')
            ->where('l.tenant_id', $tenant->id)
            ->where('l.lines_washed', '>', 0)
            ->whereNotNull('ms.beverage_type')
            ->whereRaw('COALESCE(l.machine_unit_id, ms.machine_unit_id) IS NOT NULL')
            ->selectRaw('COALESCE(l.machine_unit_id, ms.machine_unit_id) as impianto')
            ->selectRaw('ms.beverage_type as bevanda, l.lines_washed as vie, COUNT(*) as quanti')
            ->groupBy('impianto', 'bevanda', 'vie')
            ->get();

        $storico = [];

        foreach ($righe as $riga) {
            $storico[$riga->impianto.'|'.$riga->bevanda][(int) $riga->vie] = (int) $riga->quanti;
        }

        return $storico;
    }

    /**
     * @return array<string, int>
     */
    private function contaLavaggiPerVisita(Tenant $tenant): array
    {
        return DB::table('lavaggi')
            ->where('tenant_id', $tenant->id)
            ->selectRaw('customer_id, data, COUNT(*) as quanti')
            ->groupBy('customer_id', 'data')
            ->get()
            ->mapWithKeys(fn (object $riga) => [$riga->customer_id.'|'.$riga->data => (int) $riga->quanti])
            ->all();
    }

    private function lavaggiNellaVisita(Lavaggio $lavaggio): int
    {
        return $this->lavaggiPerVisita[$lavaggio->customer_id.'|'.$lavaggio->data->format('Y-m-d')] ?? 1;
    }

    private function chiaveImpianto(Lavaggio $lavaggio): ?string
    {
        $impianto = $lavaggio->machine_unit_id ?: $this->piano($lavaggio)?->machine_unit_id;
        $bevanda = $this->piano($lavaggio)?->beverage_type;

        return $impianto && $bevanda ? $impianto.'|'.$bevanda : null;
    }

    private function matricola(Lavaggio $lavaggio): string
    {
        $impianto = $lavaggio->machineUnit instanceof MachineUnit
            ? $lavaggio->machineUnit
            : $this->piano($lavaggio)?->machineUnit;

        return $impianto instanceof MachineUnit ? $impianto->serial_number : '—';
    }

    /**
     * Le relazioni di Lavaggio sono dichiarate senza generics, quindi da qui
     * arriverebbero come Model generico e nessuno si accorgerebbe di un
     * refuso su lines_count o beverage_type — i due campi da cui dipende il
     * numero che va in fattura. Questi tre passaggi servono solo a dare un
     * tipo concreto, non c'e' nessuna logica dentro.
     */
    private function piano(Lavaggio $lavaggio): ?MaintenanceSchedule
    {
        return $lavaggio->maintenanceSchedule instanceof MaintenanceSchedule
            ? $lavaggio->maintenanceSchedule
            : null;
    }

    private function cliente(Lavaggio $lavaggio): ?Customer
    {
        return $lavaggio->customer instanceof Customer ? $lavaggio->customer : null;
    }

    /**
     * Le vie scritte nella descrizione: "1 Via", "6 Vie + Chiusura",
     * "2 Vie (Vino)". Il numero è sempre in testa, prima della parola.
     */
    public static function vieDallaDescrizione(?string $descrizione): ?int
    {
        if (! $descrizione || preg_match('/^generato da rapportino/i', $descrizione)) {
            return null;
        }

        if (! preg_match('/(\d+)\s*vi[ae]\b/i', $descrizione, $m)) {
            return null;
        }

        $vie = (int) $m[1];

        // Oltre la dozzina non è un impianto, è un errore di battitura.
        return $vie > 0 && $vie <= 12 ? $vie : null;
    }
}
