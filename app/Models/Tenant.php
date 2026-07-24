<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Tenant extends Model implements HasName
{
    use HasUuids, LogsAuditTrail;

    protected $fillable = [
        'name',
        'legal_name',
        'vat_number',
        'tax_code',
        'sdi',
        'email',
        'notify_staff_emails',
        'notify_information_request_emails',
        'notify_leave_request_emails',
        'notify_quote_emails',
        'notify_quote_group_emails',
        'phone',
        'fax',
        'street',
        'postal_code',
        'city',
        'province',
        'slug',
        'is_master',
        'is_active',
        'logo_path',
        'primary_color',
        'machine_discount_percent',
        'default_commission_scenario',
        'scenario_a_commission_percent',
        'scenario_b_installation_fee',
        'scenario_c_preinstallation_fee',
        'exclusive_supply_required',
        'territory_exclusive',
        'territory_notes',
        'contract_start_date',
        'contract_duration_months',
        'notice_period_days',
        'saas_billing_enabled',
        'saas_plan_fee',
        'saas_billing_cycle',
    ];

    protected $casts = [
        'is_master' => 'boolean',
        'is_active' => 'boolean',
        'notify_staff_emails' => 'array',
        'notify_information_request_emails' => 'array',
        'notify_leave_request_emails' => 'array',
        'notify_quote_emails' => 'array',
        'notify_quote_group_emails' => 'array',
        'machine_discount_percent' => 'decimal:2',
        'scenario_a_commission_percent' => 'decimal:2',
        'scenario_b_installation_fee' => 'decimal:2',
        'scenario_c_preinstallation_fee' => 'decimal:2',
        'exclusive_supply_required' => 'boolean',
        'territory_exclusive' => 'boolean',
        'contract_start_date' => 'date',
        'contract_duration_months' => 'integer',
        'notice_period_days' => 'integer',
        'saas_billing_enabled' => 'boolean',
        'saas_plan_fee' => 'decimal:2',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function deadlines(): MorphMany
    {
        return $this->morphMany(Deadline::class, 'deadlinable');
    }

    /**
     * Relazioni richieste da Filament stesso per creare record tramite il
     * pannello (Resources\Pages\CreateRecord::associateRecordWithTenant()),
     * non solo per la lettura: senza queste, il pulsante "Nuovo" lancia
     * un'eccezione anche se il nostro global scope custom (BelongsToTenant)
     * gestisce gia' correttamente lettura e assegnazione automatica.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productFamilies(): HasMany
    {
        return $this->hasMany(ProductFamily::class);
    }

    public function productOptionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function quoteGroups(): HasMany
    {
        return $this->hasMany(QuoteGroup::class);
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(InformationRequest::class);
    }

    public function comodatoMacchinas(): HasMany
    {
        return $this->hasMany(ComodatoMacchina::class);
    }

    public function serviceReports(): HasMany
    {
        return $this->hasMany(ServiceReport::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function materialOrders(): HasMany
    {
        return $this->hasMany(MaterialOrder::class);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Restituisce i destinatari per tipo evento con fallback alla vecchia
     * lista unica (notify_staff_emails) finche' i campi specifici non sono
     * valorizzati.
     *
     * @return array<int, string>
     */
    public function notificationRecipients(string $event): array
    {
        $legacy = $this->normalizedRecipients($this->notify_staff_emails);

        return match ($event) {
            'information_request' => $this->normalizedRecipients($this->notify_information_request_emails, $legacy),
            'leave_request' => $this->normalizedRecipients($this->notify_leave_request_emails, $legacy),
            'quote' => $this->normalizedRecipients($this->notify_quote_emails, $legacy),
            'quote_group' => $this->normalizedRecipients($this->notify_quote_group_emails, $legacy),
            default => $legacy,
        };
    }

    /**
     * @param  array<int, string>|null  $fallback
     * @return array<int, string>
     */
    protected function normalizedRecipients(mixed $value, ?array $fallback = null): array
    {
        $emails = array_values(array_unique(array_filter((array) $value)));

        if (! empty($emails)) {
            return $emails;
        }

        return $fallback ?? [];
    }

    /**
     * Riga indirizzo/dati fiscali/contatti per l'intestazione dei documenti
     * PDF (preventivo, ordine materiali, rapportino) — prima calcolate in modo
     * identico e indipendente in ciascun template, con rischio di disallineamento
     * a ogni modifica del formato. Vedi resources/views/components/pdf-letterhead.blade.php.
     */
    public function pdfAddressLine(): ?string
    {
        $line = trim("{$this->street}, {$this->postal_code} {$this->city}".($this->province ? " ({$this->province})" : ''), ' ,');

        return $line ?: null;
    }

    public function pdfFiscalLine(): ?string
    {
        $line = trim(collect([
            ($this->tax_code || $this->vat_number) ? 'C.F. - P.I. '.($this->tax_code ?: $this->vat_number) : null,
            $this->sdi ? "Codice SDI: {$this->sdi}" : null,
        ])->filter()->implode(' — '));

        return $line ?: null;
    }

    public function pdfContactLine(): ?string
    {
        $line = trim(collect([
            $this->phone ? "Tel. {$this->phone}" : null,
            $this->fax ? "Fax. {$this->fax}" : null,
            $this->email,
        ])->filter()->implode(' — '));

        return $line ?: null;
    }
}
