<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    use BelongsToTenant, HasUuids, SharedAcrossTenants;

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'code',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
