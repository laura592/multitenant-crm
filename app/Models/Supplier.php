<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Models\Concerns\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail, SharedAcrossTenants;

    protected $fillable = [
        'tenant_id',
        'name',
        'address',
        'postal_code',
        'city',
        'province',
        'phone',
        'email',
        'notes',
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function materialOrders(): HasMany
    {
        return $this->hasMany(MaterialOrder::class);
    }
}
