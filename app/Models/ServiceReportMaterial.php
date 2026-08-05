<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceReportMaterial extends Model
{
    use HasUuids;

    protected $fillable = [
        'service_report_id',
        'material_id',
        'quantity',
        'unit_cost_snapshot',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost_snapshot' => 'decimal:2',
    ];

    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
