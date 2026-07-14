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
        'comodato_macchina_id',
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
        'technician_signature_path',
        'signed_at',
        'notes',
    ];

    protected $attributes = [
        'status' => 'bozza',
    ];

    protected $casts = [
        'intervention_date' => 'date',
        'arrival_at' => 'datetime',
        'departure_at' => 'datetime',
        'signed_at' => 'datetime',
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

    public function comodatoMacchina(): BelongsTo
    {
        return $this->belongsTo(ComodatoMacchina::class);
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

    public function emails(): HasMany
    {
        return $this->hasMany(ServiceReportEmail::class);
    }

    public function isSigned(): bool
    {
        return ! is_null($this->signed_at);
    }
}
