<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * gestionale" in ServiceReportResource).
     */
    public const CLOSED_STATUSES = ['firmato', 'inviato'];

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
        'machine_unit_id',
        'quote_id',
        'machine_product_id',
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
     *   Lavaggio::booted()->recalculateLavaggioNextDue().
     */
    public function syncMaintenanceSchedule(): void
    {
        $schedules = $this->machine_unit_id
            ? MaintenanceSchedule::query()
                ->where('customer_id', $this->customer_id)
                ->where('machine_unit_id', $this->machine_unit_id)
                ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
                ->get()
            : collect();

        foreach ($schedules->where('type', MaintenanceSchedule::TYPE_MANUTENZIONE) as $schedule) {
            $schedule->recalculateFromServiceReports();
        }

        $this->syncGeneratedLavaggi($schedules->where('type', MaintenanceSchedule::TYPE_LAVAGGIO));
    }

    /**
     * @param  Collection<int, MaintenanceSchedule>  $lavaggioSchedules  piani tipo lavaggio attivi per questa macchina/cliente
     */
    private function syncGeneratedLavaggi($lavaggioSchedules): void
    {
        // trashed(): sul rapportino cancellato (static::deleted, dopo il soft
        // delete) non deve restare nessun lavaggio generato, a prescindere da
        // stato/tipo — la whereNotIn qui sotto altrimenti risparmierebbe quello
        // sul piano ancora "corrente" per questa macchina.
        $qualifies = ! $this->trashed() && $this->isClosed() && $this->countsAsLavaggio();
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
            Lavaggio::updateOrCreate(
                ['service_report_id' => $this->id, 'maintenance_schedule_id' => $schedule->id],
                [
                    'tenant_id' => $this->tenant_id,
                    'customer_id' => $this->customer_id,
                    'machine_unit_id' => $this->machine_unit_id,
                    'data' => $this->intervention_date,
                    'descrizione' => "Generato da rapportino {$this->number}",
                ],
            );
        }
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Vedi il commento su LAVAGGIO_KEYWORDS per il perche' del testo libero.
     */
    public function countsAsLavaggio(): bool
    {
        if ($this->intervention_type === self::TYPE_MANUTENZIONE_ORDINARIA) {
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
     */
    public static function nextNumberForTenant(?string $tenantId): string
    {
        $year = date('Y');
        $prefix = "RT-{$year}-";

        $last = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function machineProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'machine_product_id');
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

    public function isSigned(): bool
    {
        return ! is_null($this->signed_at);
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

        $articleProduct = $this->machineProduct ?? $this->machineUnit?->product;
        if (blank($articleProduct?->gestionale_code)) {
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
     * machineUnit.product, machineUnit.billingCustomer, customer.billingCustomer,
     * materialsUsed.material gia' caricati. Chiamare gestionaleValidationErrors()
     * prima: qui non si ripetono quei controlli.
     *
     * @return array<string, mixed>
     */
    public function toGestionalePayload(): array
    {
        $articleProduct = $this->machineProduct ?? $this->machineUnit?->product;
        $recipient = $this->invoiceRecipient();

        $payload = [
            'intestatario' => ['id_eureka' => $this->customer->gestionale_code],
            'sl_articolo' => ['id_eureka' => $articleProduct?->gestionale_code],
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
