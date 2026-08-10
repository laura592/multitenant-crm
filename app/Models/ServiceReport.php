<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceReport extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_INSTALLAZIONE = 'installazione';
    public const TYPE_MANUTENZIONE_ORDINARIA = 'manutenzione_ordinaria';
    public const TYPE_MANUTENZIONE_STRAORDINARIA = 'manutenzione_straordinaria';
    public const TYPE_RIPARAZIONE = 'riparazione';
    public const TYPE_GARANZIA = 'garanzia';

    protected $fillable = [
        'tenant_id',
        'customer_id',
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
            ->orderByRaw("CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC")
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
        return $this->machineUnit?->billingCustomer ?? $this->customer->invoiceRecipient();
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

        $serialNumber = $this->machineUnit?->serial_number ?? $this->machine_serial_number;
        if ($serialNumber) {
            $payload['sl_matricola'] = $serialNumber;
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
