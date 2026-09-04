<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Models\Concerns\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail, SharedAcrossTenants;

    public const SOURCE_MANUALE = 'manuale';

    public const SOURCE_EUREKA = 'eureka';

    protected $fillable = [
        'maintenance_code',
        'tenant_id',
        'source',
        'supplier_id',
        'code',
        'gestionale_code',
        'list_price',
        'category',
        'type',
        'variant',
        'tube_diameter',
        'tube_diameter_2',
        'thread_size',
        'thread_type',
        'barb_diameter',
        'notes',
    ];

    protected $casts = [
        'list_price' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Material non ha un campo "nome" libero (pensato per raccordi
     * idraulici, identificati da code/type/variant) — questa label composta
     * serve ovunque va mostrato in modo leggibile (selettore ricambi
     * rapportino, PDF, payload Eureka).
     */
    public function getDisplayLabelAttribute(): string
    {
        return trim(implode(' — ', array_filter([$this->type, $this->variant])) ?: $this->code);
    }
}
