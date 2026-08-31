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
    public const CLOSED_STATUSES = ['inviato', 'completato'];

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
        'status',
        'customer_signature_path',
        'customer_signature_name',
        'technician_signature_path',
        'signed_at',
        'notes',
        'eureka_service_report_id',
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
     * Un rapportino non e' piu' modificabile da CRM quando:
     * - e' arrivato da Eureka (SOURCE_EUREKA, vedi ImportEurekaServiceReports), o
     * - e' gia' stato inviato con successo a Eureka (gestionale_sync_status=sent,
     *   vedi SendServiceReportToGestionaleJob).
     *
     * In entrambi i casi Eureka e' ormai (anche) la fonte autorevole per quel
     * documento, e una modifica lato CRM andrebbe fuori sincrono col gestionale
     * senza che nessuno se ne accorga.
     *
     * "Completato" NON blocca piu' (2026-08-31). Era un flag impostato a mano,
     * e trattarlo come irreversibile significava che un errore di battitura
     * accorto dopo aver spuntato la casella non si poteva piu' correggere:
     * l'unica via era cancellare e rifare. Il blocco resta legato all'unico
     * fatto che lo giustifica davvero, cioe' che il documento sia gia' passato
     * in Eureka — che e' anche cio' che questo stato diventera' quando l'invio
     * sara' automatico.
     *
     * Usato da ServiceReportPolicy::update().
     */
    public function isLocked(): bool
    {
        return $this->source === self::SOURCE_EUREKA
            || $this->gestionale_sync_status === 'sent';
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

        $last = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last->number, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
     * Se l'intervento e' su una macchina con un pagatore diverso dal cliente
     * (es. matricola in comodato pagata da un gestore terzo), fattura a
     * quella macchina prevale sul billing_customer_id generico del cliente.
     */
    public function invoiceRecipient(): Customer
    {
        if (! $this->customer) {
            throw new \RuntimeException('Cliente collegato a questo rapportino non trovato (probabilmente eliminato).');
        }

        return $this->machineUnit?->billingCustomer ?? $this->customer->invoiceRecipient();
    }

    public function getMachineUnitDisplayNameAttribute(): ?string
    {
        return $this->machineUnit
            ? $this->machineUnit->display_name . ' — ' . $this->machineUnit->serial_number
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
