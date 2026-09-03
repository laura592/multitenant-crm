<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class ServiceReport extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const TYPE_INSTALLAZIONE = 'installazione';

    public const TYPE_MANUTENZIONE_ORDINARIA = 'manutenzione_ordinaria';

    public const TYPE_MANUTENZIONE_STRAORDINARIA = 'manutenzione_straordinaria';

    public const TYPE_RIPARAZIONE = 'riparazione';

    public const TYPE_GARANZIA = 'garanzia';

    public const TYPE_SANIFICAZIONE = 'sanificazione';

    /**
     * Chi ha creato per primo questo rapportino — non lo stesso concetto di
     * "e' collegato a Eureka" (eureka_service_report_id si valorizza anche
     * per un rapportino nato qui e poi inviato). SOURCE_EUREKA e' impostato
     * solo da ImportEurekaServiceReports al momento della creazione, e resta
     * l'unico modo affidabile per sapere se il CRM o Eureka e' la fonte
     * autorevole per tecnico/data/descrizioni di questo rapportino. Ogni
     * rapportino ha un "number" interno CRM (RT-...), nato qui o assegnato
     * da ImportEurekaServiceReports per lo storico ripescato da Eureka —
     * stabile per tutta la vita del rapportino. Il numero lato Eureka (sia
     * quello di un documento importato, sia quello ottenuto da un invio) va
     * invece in "gestionale_number", vedi
     * SendServiceReportToGestionaleJob::resolveGestionaleNumber() e
     * ImportEurekaServiceReports::resolveGestionaleNumber().
     */
    public const SOURCE_MANUALE = 'manuale';

    public const SOURCE_EUREKA = 'eureka';

    /**
     * Stati che contano come "rapportino chiuso" per gli scopi di
     * syncMaintenanceSchedule() (stesso set usato dall'azione "Invia a
     * gestionale" in ServiceReportResource). "chiuso" e' un concetto
     * distinto da "e' 'inviato'": "completato" (rapportini gia' passati in
     * amministrazione, vedi ServiceReportResource::statusLabels()) conta
     * anche lui come chiuso, "rifiutato" no.
     */
    /** Anche "in_gestionale": il documento e' passato in Eureka, piu' chiuso di cosi'. */
    public const CLOSED_STATUSES = ['inviato', 'completato', 'in_gestionale'];

    /**
     * Parole chiave usate da countsAsLavaggio() sullo storico importato da Eureka,
     * dove il lavaggio/sanificazione non e' un intervention_type a se' ma finisce
     * descritto in problem_description/work_performed/notes (vedi es. RT importati
     * con "Sanificazione impianto" schedati come manutenzione_ordinaria, e casi piu'
     * rari come una riparazione che menziona la sanificazione fatta a margine).
     */
    private const LAVAGGIO_KEYWORDS = ['lavagg', 'puliz', 'sanific'];

    /**
     * Codici materiale Eureka che sono veri e propri servizi di lavaggio/sanificazione
     * linee (non ricambi fisici): usati come firma su rapportini di tipo "riparazione"
     * senza alcun testo in problem_description/work_performed/notes (es. SL-583, dove
     * l'unica traccia del lavaggio e' il ricambio "LAV2MART" = "LAVAGGIO 2 VIE" tra i
     * materialsUsed). Elenco tenuto volutamente stretto: il catalogo Eureka ha anche
     * ricambi fisici con "lavaggio/pulizia" nel nome (es. "BRACCIO DI LAVAGGIO
     * DELL'ASSE", pezzo di lavastoviglie; "DETERGENTE PULIZIA LANCIA VAPORE",
     * detergente per macchine da caffe') che non c'entrano col piano lavaggio impianti
     * bevande e andrebbero a creare collegamenti sbagliati.
     */
    private const LAVAGGIO_MATERIAL_CODES = ['LAV2', 'LAV2MART', 'LAVMART', 'SANIFICAZIONE'];

    protected $fillable = [
        'tenant_id',
        'source',
        'customer_id',
        'billing_customer_id',
        'number',
        'gestionale_number',
        'gestionale_document_date',
        'machine_unit_id',
        'quote_id',
        'machine_product_id',
        'machine_material_id',
        'machine_serial_number',
        'technician_id',
        'intervention_type',
        'intervention_date',
        'arrival_at',
        'departure_at',
        'problem_description',
        'work_performed',
        'lavaggio_vie_count',
        'status',
        'customer_signature_path',
        'customer_signature_name',
        'technician_signature_path',
        'signed_at',
        'notes',
        'eureka_service_report_id',
        'duplicato_suggerito_id',
        'duplicato_suggerito_motivo',
        'eureka_destinazione_code',
        'eureka_destinazione_label',
        'eureka_stato_documento',
        'eureka_stato_label',
        'gestionale_scheda_lavoro_id',
        'gestionale_sync_status',
        'gestionale_sync_error',
        'gestionale_synced_at',
    ];

    protected $attributes = [
        'status' => 'bozza',
    ];

    protected $casts = [
        'intervention_date' => 'date',
        'lavaggio_vie_count' => 'integer',
        'gestionale_document_date' => 'date',
        'arrival_at' => 'datetime',
        'departure_at' => 'datetime',
        'signed_at' => 'datetime',
        'gestionale_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $report) {
            if (! $report->number) {
                $report->number = static::nextNumberForTenant($report->tenant_id);
            }
        });

        // Le FK cascadeOnDelete() del DB non scattano piu' su un soft delete
        // (e' un UPDATE, non una DELETE): replichiamo la cascata a mano su
        // ricambi/materiali usati ed email.
        static::deleting(function (self $report) {
            $report->partsUsed->each->delete();
            $report->materialsUsed->each->delete();
            $report->emails->each->delete();
        });

        // Alla chiusura di un rapportino di manutenzione ordinaria (docs/architecture.md
        // §13.1), il piano di manutenzione collegato (stessa macchina) va aggiornato con
        // l'ultima visita. Anche su delete/soft-delete: se si cancella l'ultimo rapportino
        // che aveva chiuso il piano, la scadenza deve ricadere sul precedente (o sparire).
        static::saved(function (self $report) {
            // Alla chiusura il pagante si fissa sul documento: da li' in poi
            // il rapportino ricorda chi pagava quando e' stato fatto, e non
            // cambia piu' se domani il cliente passa a un altro torrefattore.
            if ($report->isClosed()) {
                $report->freezeInvoiceRecipient();
            }

            $report->syncMaintenanceSchedule();
        });

        static::deleted(function (self $report) {
            $report->syncMaintenanceSchedule();
        });
    }

    /**
     * Trova i piani di manutenzione attivi per la stessa macchina/cliente e li
     * riallinea a questo rapportino. Nessun campo esplicito maintenance_schedule_id
     * su ServiceReport (a differenza di Lavaggio): il collegamento e' inferito da
     * customer_id+machine_unit_id, per non aggiungere una selezione manuale extra
     * al form di ogni rapportino.
     *
     * - piano tipo "manutenzione": riallineato da recalculateFromServiceReports()
     *   (solo intervention_type=manutenzione_ordinaria chiuso).
     * - piano tipo "lavaggio": qui non esiste ancora un intervention_type dedicato
     *   ("lavaggio"), quindi un rapportino che conta come lavaggio (countsAsLavaggio())
     *   genera/aggiorna una riga Lavaggio agganciata a questo rapportino
     *   (service_report_id) — il piano si riallinea da solo via
     *   Lavaggio::booted()->recalculateLavaggioNextDue(). Generata gia' in
     *   bozza (non solo da chiuso, vedi syncGeneratedLavaggi()): si vede
     *   subito nello storico lavaggi mentre il rapportino viene compilato, e
     *   si ripulisce da sola se la bozza viene scartata o cambiata.
     */
    public function syncMaintenanceSchedule(): void
    {
        // La manutenzione resta sempre specifica per macchina: senza
        // machine_unit_id sul rapportino non si sa quale macchina sia stata
        // davvero manutenuta, meglio non toccare nessun piano che indovinare.
        $manutenzioneSchedules = $this->machine_unit_id
            ? MaintenanceSchedule::query()
                ->where('customer_id', $this->customer_id)
                ->where('machine_unit_id', $this->machine_unit_id)
                ->where('type', MaintenanceSchedule::TYPE_MANUTENZIONE)
                ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
                ->get()
            : collect();

        foreach ($manutenzioneSchedules as $schedule) {
            $schedule->recalculateFromServiceReports();
        }

        // Selezione esplicita (campo "Impianti/manutenzioni interessati", solo
        // sanificazione): una sanificazione puo' riguardare piu' impianti dello
        // stesso cliente senza che siano "tutti quelli attivi" ne' "solo quello
        // di machine_unit_id" — vince su entrambe le regole implicite sotto.
        // Query fresca (non la relation gia' eventualmente in cache
        // sull'istanza): un attach() successivo su questo stesso oggetto,
        // come su un secondo giro di saveRelationships(), non invaliderebbe
        // da solo una collezione gia' caricata.
        $explicitSchedules = $this->maintenanceSchedules()->get();
        if ($explicitSchedules->isNotEmpty()) {
            $this->syncGeneratedLavaggi($explicitSchedules);

            return;
        }

        // Il lavaggio invece e' spesso "tutti gli impianti in una visita"
        // (machine_unit_id lasciato vuoto apposta, vedi helperText su
        // LavaggiRelationManager: "il caso normale"): in quel caso il
        // rapportino riguarda TUTTI i piani lavaggio attivi del cliente, non
        // nessuno. Prima machine_unit_id nullo risolveva a "nessun piano",
        // lasciando la sincronizzazione muta e — peggio — facendo apparire
        // "orfano" (quindi da cancellare) qualunque Lavaggio gia' generato in
        // precedenza da questo stesso rapportino, vedi syncGeneratedLavaggi().
        $lavaggioSchedules = MaintenanceSchedule::query()
            ->where('customer_id', $this->customer_id)
            ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
            ->when(
                $this->machine_unit_id,
                fn ($query) => $query->where('machine_unit_id', $this->machine_unit_id),
            )
            ->get();

        $this->syncGeneratedLavaggi($lavaggioSchedules);
    }

    /**
     * @param  Collection<int, MaintenanceSchedule>  $lavaggioSchedules  piani tipo lavaggio attivi per questa macchina/cliente
     */
    private function syncGeneratedLavaggi($lavaggioSchedules): void
    {
        // trashed(): sul rapportino cancellato (static::deleted, dopo il soft
        // delete) non deve restare nessun lavaggio generato, a prescindere da
        // stato/tipo — la whereNotIn qui sotto altrimenti risparmierebbe quello
        // sul piano ancora "corrente" per questa macchina. Niente piu'
        // isClosed(): la riga si crea gia' in bozza (vedi syncMaintenanceSchedule()),
        // cosi' lo storico lavaggi la mostra subito invece di sparire finche'
        // il rapportino non viene inviato/completato.
        $qualifies = ! $this->trashed() && $this->countsAsLavaggio();
        $scheduleIds = $lavaggioSchedules->pluck('id');

        // Ripulisce i lavaggi generati da una versione precedente di questo
        // rapportino che non sono (piu') validi: rapportino non piu' chiuso/non
        // piu' "da lavaggio", o macchina/cliente cambiati (piano non piu' tra
        // quelli correnti). Lavaggio::booted() ricalcola da solo il piano
        // interessato alla cancellazione.
        $stale = Lavaggio::where('service_report_id', $this->id);
        if ($qualifies) {
            $stale->whereNotIn('maintenance_schedule_id', $scheduleIds);
        }
        $stale->get()->each->delete();

        if (! $qualifies) {
            return;
        }

        foreach ($lavaggioSchedules as $schedule) {
            $lavaggio = Lavaggio::firstOrNew([
                'service_report_id' => $this->id,
                'maintenance_schedule_id' => $schedule->id,
            ]);

            // Non risovrascrivere una descrizione gia' personalizzata (a
            // mano, o importata da uno storico con piu' dettaglio del
            // placeholder generico): solo le righe nuove o ancora col
            // placeholder di default vengono (ri)generate.
            $isGenericOrNew = ! $lavaggio->exists || $lavaggio->descrizione === "Generato da rapportino {$this->number}";

            $lavaggio->fill([
                'tenant_id' => $this->tenant_id,
                'customer_id' => $this->customer_id,
                'machine_unit_id' => $this->machine_unit_id,
                'data' => $this->intervention_date,
            ]);

            if ($isGenericOrNew) {
                $lavaggio->descrizione = "Generato da rapportino {$this->number}";
            }

            $lavaggio->save();
        }
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Il documento esiste davvero su Eureka. E' un FATTO, non una scelta:
     * - e' arrivato da li' (SOURCE_EUREKA, vedi ImportEurekaServiceReports),
     * - o e' stato inviato con successo (gestionale_sync_status=sent, vedi
     *   SendServiceReportToGestionaleJob),
     * - o e' agganciato a una scheda lavoro Eureka (eureka_service_report_id),
     *   che e' il caso di un rapportino nostro unito al suo doppione
     *   importato: vive su Eureka pur non essendo mai passato da un invio
     *   CRM->Eureka.
     *
     * Stessa domanda a cui risponde ServiceReportResource::gestionaleDisplayState()
     * per decidere il badge "Stato invio Eureka".
     */
    public function isSuEureka(): bool
    {
        return $this->source === self::SOURCE_EUREKA
            || $this->gestionale_sync_status === 'sent'
            || $this->eureka_service_report_id !== null;
    }

    /**
     * Un rapportino non e' piu' modificabile da CRM quando esiste su Eureka:
     * li' e' ormai (anche) la fonte autorevole, e una modifica lato CRM
     * andrebbe fuori sincrono col gestionale senza che nessuno se ne accorga.
     *
     * Il blocco NON guarda piu' lo stato (2026-09-03). Fino a ieri
     * "in_gestionale" bloccava, e per questo non era scegliibile a mano dal
     * form: metterlo avrebbe chiuso il rapportino per sbaglio. Ora lo stato
     * torna a essere solo un'etichetta amministrativa, che l'ufficio imposta
     * quando vuole, e a chiudere e' il fatto — isSuEureka(). Le due cose
     * coincidono comunque nella pratica: tutti i rapportini in produzione
     * con stato "in_gestionale" hanno gia' eureka_service_report_id, quindi
     * nessuno di quelli chiusi si e' riaperto con questo cambio.
     *
     * "Completato" NON blocca piu' (2026-08-31). Era un flag impostato a mano,
     * e trattarlo come irreversibile significava che un errore di battitura
     * accorto dopo aver spuntato la casella non si poteva piu' correggere:
     * l'unica via era cancellare e rifare.
     *
     * Usato da ServiceReportPolicy::update().
     */
    public function isLocked(): bool
    {
        return $this->isSuEureka();
    }

    /**
     * Vedi il commento su LAVAGGIO_KEYWORDS per il perche' del testo libero.
     * TYPE_SANIFICAZIONE e' il tipo intervento dedicato ai lavaggi creati da
     * qui in poi (vedi LavaggiRelationManager::serviceReportCreateUrl()):
     * riconosciuto sempre, anche senza testo libero corrispondente.
     * TYPE_MANUTENZIONE_ORDINARIA resta comunque riconosciuto per lo storico
     * pre-esistente e per l'import da Eureka, dove il lavaggio finisce
     * ancora schedato sotto quel tipo (vedi LAVAGGIO_KEYWORDS).
     */
    public function countsAsLavaggio(): bool
    {
        if (in_array($this->intervention_type, [self::TYPE_MANUTENZIONE_ORDINARIA, self::TYPE_SANIFICAZIONE], true)) {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            $this->problem_description,
            $this->work_performed,
            $this->notes,
        ])));

        foreach (self::LAVAGGIO_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        // Vedi LAVAGGIO_MATERIAL_CODES: cattura i casi (come SL-583) senza alcun
        // testo libero, dove il lavaggio si vede solo dal ricambio usato.
        if ($this->materialsUsed->contains(
            fn (ServiceReportMaterial $part) => in_array($part->material?->code, self::LAVAGGIO_MATERIAL_CODES, true)
        )) {
            return true;
        }

        return false;
    }

    /**
     * Numerazione scoped per tenant fin dall'inizio (vedi docs/architecture.md §10.5).
     * Anno di default quello odierno (rapportino creato ora in CRM); un anno
     * esplicito serve per assegnare un numero coerente con la data reale a
     * uno storico ripescato da Eureka (vedi ImportEurekaServiceReports e
     * BackfillServiceReportCrmNumbers), dove created_at riflette quando la
     * riga e' stata importata, non l'anno dell'intervento — per questo il
     * filtro e' solo sul prefisso di "number", mai su created_at.
     */
    public static function nextNumberForTenant(?string $tenantId, ?int $year = null): string
    {
        $year ??= (int) date('Y');
        $prefix = "RT-{$year}-";

        // Si prende l'ULTIMO piu' uno, mai un numero gia' usato.
        //
        // Per un giorno (02-03/09/2026) si riempivano i buchi lasciati dalle
        // unioni, per non lasciare la serie bucata. La regola e' caduta il
        // 03/09: l'ufficio stampa il riepilogo degli interventi e quei numeri
        // finiscono su carta, quindi un numero non puo' cambiare significato.
        // RT-2026-0579 era "Hotel Vidi Miramare" sulla stampa del 02/09;
        // riassegnarlo a una scheda importata avrebbe reso quella carta
        // bugiarda.
        //
        // I buchi restano, e vanno bene: dicono che li' c'e' stata
        // un'unione. withoutGlobalScopes() perche' anche i numeri dei
        // rapportini archiviati restano occupati.
        $ultimo = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC')
            ->value('number');

        $next = $ultimo && preg_match('/-(\d+)$/', $ultimo, $m) ? (int) $m[1] + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * La scheda lavoro importata da Eureka che il sync ritiene documenti lo
     * stesso intervento di questo rapportino (vedi
     * GestionaleSyncRunner::proponiDoppioniRapportini()). E' una proposta:
     * finche' non viene confermata i due rapportini restano entrambi.
     */
    /**
     * withTrashed() non e' un dettaglio: due rapportini possono proporre la
     * stessa scheda importata, e confermarne uno la manda in soft delete
     * lasciando l'altra proposta a puntare nel vuoto. Senza, il confronto
     * esplodeva con "Call to a member function load() on null" (visto dal
     * vivo il 02/09/2026). La proposta orfana va vista e chiusa, non fatta
     * schiantare — se ne occupa scartaProposteOrfane().
     */
    public function duplicatoSuggerito(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicato_suggerito_id')->withTrashed();
    }

    /**
     * Chiude le proposte che puntano a una scheda ormai unita a un altro
     * rapportino: non c'e' piu' niente da decidere.
     */
    public static function scartaProposteOrfane(): int
    {
        $orfane = self::query()
            ->whereNotNull('duplicato_suggerito_id')
            ->whereHas('duplicatoSuggerito', fn ($q) => $q->onlyTrashed())
            ->get();

        foreach ($orfane as $rapportino) {
            $rapportino->scartaDuplicato();
        }

        return $orfane->count();
    }

    /**
     * Accetta la proposta: tiene QUESTO rapportino — quello compilato dal
     * tecnico, con la firma del cliente e il dettaglio del lavoro — e gli
     * trasferisce il collegamento a Eureka, eliminando la copia importata.
     *
     * Si tiene il nostro e non quello del gestionale perche' la scheda
     * importata e' un riassunto amministrativo: senza firma, senza le note
     * del tecnico e spesso senza tutti i ricambi. Il collegamento a Eureka,
     * che e' l'unica cosa che il nostro non aveva, viene travasato qui.
     *
     * La copia importata viene soft-eliminata, non cancellata: se
     * l'abbinamento si rivelasse sbagliato si recupera.
     */
    public function confermaDuplicato(): void
    {
        $importato = $this->duplicatoSuggerito;

        if (! $importato) {
            return;
        }

        $this->update([
            'eureka_service_report_id' => $importato->eureka_service_report_id,
            'gestionale_scheda_lavoro_id' => $importato->gestionale_scheda_lavoro_id,
            'gestionale_number' => $importato->gestionale_number,
            'gestionale_document_date' => $importato->gestionale_document_date,
            // Chi paga davvero, se diverso dal cliente presso cui si e'
            // intervenuti. Senza questi due il rapportino unito dichiarava
            // "paga il cliente stesso" anche quando Eureka sapeva il
            // contrario (RT-2026-0586: pagava GOPPION CAFFE' SPA) — una
            // bugia su un dato di fatturazione, non un dettaglio estetico.
            'eureka_destinazione_code' => $importato->eureka_destinazione_code,
            'eureka_destinazione_label' => $importato->eureka_destinazione_label,
            // Stato grezzo del documento su Eureka: serve a distinguere una
            // scheda chiusa da una ancora aperta la'.
            'eureka_stato_documento' => $importato->eureka_stato_documento,
            'eureka_stato_label' => $importato->eureka_stato_label,
            'notes' => self::uniscoTesti($this->notes, $importato->notes),
            'problem_description' => self::uniscoTesti($this->problem_description, $importato->problem_description),
            'work_performed' => self::uniscoTesti($this->work_performed, $importato->work_performed),
            'duplicato_suggerito_id' => null,
            'duplicato_suggerito_motivo' => null,
            // Unire vuol dire constatare che il documento e' gia' in Eureka:
            // "completato" descrive un rapportino chiuso qui, e non dice a
            // chi lo riapre fra un mese che da correggere e' la scheda del
            // gestionale. E' lo stesso stato che assegna
            // SendServiceReportToGestionaleJob dopo un invio riuscito.
            // Non e' "inviato", che significa spedito via mail al cliente.
            'status' => 'in_gestionale',
        ] + $this->macchinaDaAdottare($importato));

        $this->adottaMaterialiDaEureka($importato);

        // La copia archiviata TIENE il suo numero.
        //
        // Per un giorno lo si liberava, per non lasciare buchi nella serie.
        // Ma l'ufficio stampa il riepilogo degli interventi e quei numeri
        // finiscono su carta: riassegnarne uno renderebbe bugiarda una
        // stampa gia' consegnata (indicazione dell'ufficio, 03/09/2026).
        // Il buco resta, e dice che li' c'e' stata un'unione.
        $importato->delete();

        // Unire e' irreversibile nei fatti (il rapportino importato sparisce
        // dagli elenchi) e cambia articoli, pagante e stato: e' il movimento
        // piu' pesante di tutta l'integrazione, e deve restare scritto con il
        // nome di chi lo ha deciso.
        // Un'altra proposta puo' puntare alla scheda appena consumata: senza
        // questa riga resterebbe li' fino al sync successivo, e confermarla
        // aggancerebbe il rapportino a un documento morto (visto su
        // RT-2026-0614, che proponeva la scheda del lavaggio invece di
        // quella del filtro).
        self::scartaProposteOrfane();

        \App\Support\Gestionale\RegistroSync::movimento('doppioni', 'rapportini uniti', [
            'nostro' => $this->number,
            'importato' => $importato->number,
            'scheda' => $this->gestionale_number,
            'pagante' => $this->eureka_destinazione_label,
            'deciso_da' => auth()->user()?->email,
        ]);
    }

    /**
     * Sostituisce le righe materiale con quelle della scheda importata.
     *
     * Gli articoli buoni sono quelli del gestionale (indicazione dell'ufficio,
     * 02/09/2026): e' li' che si fattura, e una riga che il tecnico ha
     * scritto in un modo e l'ufficio in un altro — LAVMART contro LAV2MART su
     * RT-2026-0581 — deve finire con il codice che Eureka riconosce.
     *
     * Le righe di qui non spariscono davvero: sono soft-deleted, quindi la
     * versione del tecnico resta consultabile se serve capire cosa e'
     * cambiato.
     *
     * Se la scheda importata non ha righe non si tocca niente: non c'e'
     * niente da adottare, e svuotare il rapportino sarebbe una perdita secca.
     */
    private function adottaMaterialiDaEureka(self $importato): void
    {
        $loro = $importato->materialsUsed;

        if ($loro->isEmpty()) {
            return;
        }

        $this->materialsUsed()->get()->each->delete();

        foreach ($loro as $riga) {
            $this->materialsUsed()->create([
                'material_id' => $riga->material_id,
                'quantity' => $riga->quantity,
                'unit_cost_snapshot' => $riga->unit_cost_snapshot,
                'line_total_snapshot' => $riga->line_total_snapshot,
                'notes' => $riga->notes,
            ]);
        }
    }

    /**
     * La macchina della scheda importata, ma solo se qui non ce n'e' una.
     *
     * Il caso che conta: il tecnico compila il rapportino senza selezionare
     * la macchina e scrive la matricola nel testo (RT-2026-0579,
     * "Serial-No. 3400000411147"), mentre la scheda di Eureka porta quella
     * matricola in chiaro. Confermare il doppione senza raccoglierla
     * butterebbe via l'unico aggancio all'apparecchio.
     *
     * Si riempie un vuoto, non si sovrascrive mai: se una macchina qui c'e'
     * gia' ed e' diversa, quella e' una discordanza che il confronto mostra
     * e che decide una persona.
     *
     * @return array<string, mixed>
     */
    private function macchinaDaAdottare(self $importato): array
    {
        if ($this->machine_unit_id !== null || $importato->machine_unit_id === null) {
            return [];
        }

        return array_filter([
            'machine_unit_id' => $importato->machine_unit_id,
            'machine_product_id' => $this->machine_product_id ?? $importato->machine_product_id,
            'machine_serial_number' => $this->machine_serial_number ?: $importato->machine_serial_number,
        ], fn ($valore) => $valore !== null);
    }

    /**
     * Unisce due testi tenendo per primo quello del CRM.
     *
     * Non si sceglie fra i due: quello del tecnico e' il piu' ricco, ma la
     * scheda di Eureka a volte riporta qualcosa che qui non c'e' (una nota
     * dell'ufficio, un riferimento a un documento). Scartarla significherebbe
     * perdere informazione in modo irreversibile, e la conferma di un
     * doppione non si annulla.
     *
     * Il testo importato viene aggiunto in coda solo se dice qualcosa di
     * diverso — se e' identico o gia' contenuto non si duplica nulla — ed e'
     * marcato, perche' chi lo rilegge fra sei mesi deve sapere da dove
     * arriva.
     */
    private static function uniscoTesti(?string $nostro, ?string $importato): ?string
    {
        $nostro = trim((string) $nostro);
        // La boilerplate di Eureka non e' contenuto: non va fusa.
        $importato = \App\Support\Gestionale\ConfrontoRapportini::testoUtile($importato);

        if ($importato === '' || $nostro === $importato) {
            return $nostro !== '' ? $nostro : null;
        }

        if ($nostro === '') {
            return $importato;
        }

        if (str_contains($nostro, $importato)) {
            return $nostro;
        }

        return $nostro."\n\nDa Eureka: ".$importato;
    }

    /** Scarta la proposta: i due rapportini restano distinti. */
    public function scartaDuplicato(): void
    {
        $this->update([
            'duplicato_suggerito_id' => null,
            'duplicato_suggerito_motivo' => null,
        ]);
    }

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    /**
     * Selezione esplicita (form "Sanificazione") di quali piani lavaggio
     * copre questo rapportino, per una sanificazione che riguarda piu'
     * impianti dello stesso cliente in una sola visita — vedi
     * syncGeneratedLavaggi(). Vuota per i rapportini che non l'hanno mai
     * usata: in quel caso vale ancora la regola implicita di prima
     * (machine_unit_id se presente, altrimenti tutti i piani attivi).
     */
    public function maintenanceSchedules(): BelongsToMany
    {
        return $this->belongsToMany(MaintenanceSchedule::class, 'service_report_maintenance_schedule');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function machineProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'machine_product_id');
    }

    /**
     * L'articolo Eureka (sl_articolo) del bene su cui si e' intervenuto, ora
     * tracciato in Materiali insieme ai ricambi invece che duplicato nel
     * catalogo preventivi — vedi la migrazione
     * add_machine_material_id_to_service_reports_table. I rapportini storici
     * hanno ancora solo machine_product_id: leggerli passa sempre da
     * gestionaleArticle()/machine_model_name, mai da uno dei due campi da solo.
     */
    public function machineMaterial(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'machine_material_id');
    }

    /**
     * Il bene su cui si e' intervenuto, qualunque tabella lo ospiti: Product
     * (rapportini storici e macchine a listino), Material (articolo Eureka,
     * dai nuovi import) o il modello della matricola. L'ordine ricalca quello
     * che c'era prima, con il materiale inserito in mezzo: un rapportino gia'
     * agganciato a un Product continua a comportarsi esattamente come prima.
     */
    public function gestionaleArticle(): Product|Material|null
    {
        return $this->machineProduct ?? $this->machineMaterial ?? $this->machineUnit?->product;
    }

    /**
     * Nome leggibile del bene, per infolist/PDF/tabelle. Material non ha un
     * campo nome libero (nato per i raccordi): per gli articoli Eureka la
     * descrizione sta in `type` e display_label la ricompone.
     */
    public function getMachineModelNameAttribute(): ?string
    {
        return $this->machineProduct?->name
            ?? $this->machineMaterial?->display_label
            ?? $this->machineUnit?->product?->name
            ?? $this->machineUnit?->model_name;
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function partsUsed(): HasMany
    {
        return $this->hasMany(ServiceReportProduct::class);
    }

    /**
     * Ricambi/materiali usati, da Materiali (App\Models\Material) — sostituisce
     * partsUsed (Product) per i rapportini nuovi: quel campo pescava senza
     * filtro dallo stesso catalogo usato per i preventivi, mescolando
     * ricambi/macchine trovate su Eureka al listino ufficiale. partsUsed
     * resta intatto per i rapportini gia' compilati (storico).
     */
    public function materialsUsed(): HasMany
    {
        return $this->hasMany(ServiceReportMaterial::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(ServiceReportEmail::class)->latest();
    }

    /**
     * I lavaggi generati da questo rapportino: uno per impianto coperto dalla
     * visita (una sanificazione ne tocca spesso piu' d'uno).
     */
    public function lavaggi(): HasMany
    {
        return $this->hasMany(Lavaggio::class);
    }

    /**
     * Vie lavate in tutto il rapportino, sommate su tutti gli impianti.
     * null quando il tecnico non le ha indicate: in quel caso il dato non si
     * stampa, invece di mostrare uno zero che sembrerebbe "nessuna via lavata".
     */
    public function totalLinesWashed(): ?int
    {
        $righe = $this->relationLoaded('lavaggi') ? $this->lavaggi : $this->lavaggi()->get();
        $valorizzate = $righe->whereNotNull('lines_washed');

        return $valorizzate->isEmpty() ? null : (int) $valorizzate->sum('lines_washed');
    }

    public function isSigned(): bool
    {
        return ! is_null($this->signed_at);
    }

    /**
     * Cliente locale corrispondente a eureka_destinazione_code (il pagante
     * reale secondo Eureka, vedi ImportEurekaServiceReports), se gia'
     * presente in CRM con quel gestionale_code. Puo' tornare null anche con
     * eureka_destinazione_label valorizzato: Eureka conosce quel pagante,
     * ma non e' detto che esista gia' come Customer qui.
     */
    public function eurekaDestinazionePayer(): ?Customer
    {
        if (! $this->eureka_destinazione_code) {
            return null;
        }

        return Customer::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('gestionale_code', $this->eureka_destinazione_code)
            ->first();
    }

    /**
     * Il pagante di QUESTO documento.
     *
     * Se e' stato congelato (billing_customer_id sul rapportino) vince
     * sempre: e' chi paga per questo intervento, deciso quando l'intervento
     * e' stato chiuso. Cambiare il pagante di un cliente o di una macchina
     * oggi non deve riscrivere chi ha pagato due anni fa.
     *
     * Solo se manca si ricalcola come prima: la macchina (se ha un pagatore
     * suo, es. matricola in comodato pagata da un gestore terzo) prevale sul
     * billing_customer_id generico del cliente.
     */
    public function invoiceRecipient(): Customer
    {
        if ($this->billingCustomer) {
            return $this->billingCustomer;
        }

        if (! $this->customer) {
            throw new \RuntimeException('Cliente collegato a questo rapportino non trovato (probabilmente eliminato).');
        }

        return $this->machineUnit?->billingCustomer ?? $this->customer->invoiceRecipient();
    }

    /**
     * Il pagante congelato su questo documento. Nullo sui rapportini vecchi
     * senza snapshot Eureka, dove invoiceRecipient() ricalcola.
     */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }

    /**
     * Fissa il pagante sul documento, se non c'e' gia'.
     *
     * Chiamato alla chiusura: da li' in poi quel rapportino ricorda chi
     * pagava quando e' stato fatto, e non cambia piu' se domani il cliente
     * passa a un altro torrefattore.
     */
    public function freezeInvoiceRecipient(): void
    {
        if ($this->billing_customer_id || ! $this->customer) {
            return;
        }

        $pagante = $this->machineUnit?->billingCustomer ?? $this->customer->invoiceRecipient();

        $this->forceFill(['billing_customer_id' => $pagante->id])->saveQuietly();
    }

    public function getMachineUnitDisplayNameAttribute(): ?string
    {
        return $this->machineUnit
            ? $this->machineUnit->display_name.' — '.$this->machineUnit->serial_number
            : null;
    }

    /**
     * Controlli da fare PRIMA di chiamare EurekaClient::inviaSchedaLavoro(),
     * per mostrare un errore leggibile invece di scoprirlo dalla risposta HTTP.
     *
     * @return array<int, string>
     */
    public function gestionaleValidationErrors(): array
    {
        $errors = [];

        if (! $this->customer) {
            $errors[] = 'Il cliente collegato a questo rapportino risulta eliminato.';

            return $errors;
        }

        if (blank($this->customer->gestionale_code)) {
            $errors[] = "Il cliente \"{$this->customer->full_name}\" non ha un codice gestionale (Eureka).";
        }

        if (blank($this->gestionaleArticle()?->gestionale_code)) {
            $errors[] = 'Il prodotto macchina di questo intervento non ha un codice Eureka collegato.';
        }

        if (blank($this->problem_description)) {
            $errors[] = 'Manca la descrizione del problema (sl_sintomo e\' obbligatorio per Eureka).';
        }

        $recipient = $this->invoiceRecipient();
        if ($recipient->isNot($this->customer) && blank($recipient->gestionale_code)) {
            $errors[] = "Il cliente da fatturare (\"{$recipient->full_name}\") non ha un codice gestionale (Eureka).";
        }

        return $errors;
    }

    /**
     * Body per POST /schedelavoro/ di Eureka. Richiede customer, machineProduct,
     * machineMaterial, machineUnit.product, machineUnit.billingCustomer,
     * customer.billingCustomer, materialsUsed.material gia' caricati. Chiamare
     * gestionaleValidationErrors() prima: qui non si ripetono quei controlli.
     *
     * @return array<string, mixed>
     */
    public function toGestionalePayload(): array
    {
        $recipient = $this->invoiceRecipient();

        $payload = [
            'intestatario' => ['id_eureka' => $this->customer->gestionale_code],
            // Stesso identificatore del dettaglio ricambi qui sotto (l'id
            // articolo di Eureka): che il bene arrivi da Prodotti o da
            // Materiali, per loro e' la stessa anagrafica.
            'sl_articolo' => ['id_eureka' => $this->gestionaleArticle()?->gestionale_code],
            // Da doc fornitore: usare sempre id=2 ("FISSA"). In produzione l'id 2
            // e' pero' "MAN"/MANODOPERA STD (nessuna tariffa "FISSA" esiste
            // davvero) — confermato dal fornitore (2026-08-06) che e' solo una
            // svista di nome nella loro doc, l'id da usare resta sempre 2.
            'sl_tariffa' => ['id_eureka' => 2],
            'sl_sintomo' => $this->problem_description,
            'sl_lavorazione' => $this->work_performed,
            'dettaglio' => $this->materialsUsed->map(fn (ServiceReportMaterial $part) => [
                'id_articolo' => $part->material?->gestionale_code ?? 0,
                'descrizione' => $part->material?->display_label ?? '',
                'um' => 'NR',
                'quantita' => (float) $part->quantity,
            ])->all(),
        ];

        // sl_matricola e' opzionale, ma se presente Eureka la valida contro
        // le matricole gia' registrate per quell'articolo (422 se non
        // trovata) — vedi doc fornitore §6.1. Va quindi inviata solo per una
        // MachineUnit con source=eureka (matricola vista dal vivo sul loro
        // sistema, es. da un import storico o da art_installati): una
        // creata a mano nel CRM (nuova installazione appena documentata,
        // impianto segnaposto) non e' detto sia gia' registrata li', e
        // mandarla comunque fa fallire l'intero invio — successo davvero,
        // vedi RT-2026-0003/0005/0006/0007 (matricola inventata o non
        // ancora nota, tutti falliti con 422 finche' non tolta).
        if ($this->machineUnit?->source === MachineUnit::SOURCE_EUREKA) {
            $payload['sl_matricola'] = $this->machineUnit->serial_number;
        }

        if ($recipient->isNot($this->customer)) {
            $payload['destinazione'] = [
                'id_eureka' => $recipient->gestionale_code,
                'rag_sociale' => $recipient->company_name ?: $recipient->full_name,
                'indirizzo' => $recipient->street,
                'cap' => $recipient->postal_code,
                'citta' => $recipient->city,
                'sigla_prov' => $recipient->province,
                'email' => $recipient->primaryEmail(),
                'telefono' => $recipient->primaryPhone(),
            ];
        }

        return $payload;
    }
}
