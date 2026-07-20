<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Storico degli spostamenti di un MachineUnit: una riga per ogni periodo in
 * cui la macchina e' stata presso un cliente (o in magazzino se
 * customer_id e' null). removed_at nullo = posizionamento tuttora attivo.
 */
class MachineUnitPlacement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'machine_unit_id',
        'customer_id',
        'placed_at',
        'removed_at',
        'notes',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
