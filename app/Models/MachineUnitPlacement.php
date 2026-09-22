<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Storico degli spostamenti di un MachineUnit: una riga per ogni periodo in
 * cui la macchina e' stata presso un cliente (o in magazzino se
 * customer_id e' null). removed_at nullo = posizionamento tuttora attivo.
 *
 * Anche chi pagava in quel periodo (billing_customer_id, vuoto = il cliente
 * stesso): dipende da dove la macchina e' installata, non dalla macchina.
 * Quello della posizione attuale e' copiato su MachineUnit.
 */
class MachineUnitPlacement extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'machine_unit_id',
        'customer_id',
        'billing_customer_id',
        'eureka_billing_customer_code',
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

    /** Chi pagava in questo periodo, se non il cliente stesso (22/09/2026). */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }
}
