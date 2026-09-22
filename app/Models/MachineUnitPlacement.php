<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\DisplayName;
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

    /**
     * Il pagante che Eureka indica per questa consegna, se diverso dal
     * cliente stesso. Null anche quando Eureka non lo dice.
     */
    public function paganteEureka(): ?Customer
    {
        if (! $this->eureka_billing_customer_code) {
            return null;
        }

        $pagante = Customer::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->where('gestionale_code', $this->eureka_billing_customer_code)
            ->first();

        return $pagante && $pagante->id !== $this->customer_id ? $pagante : null;
    }

    /**
     * L'avviso da mostrare quando si sceglie un pagante diverso da quello
     * di Eureka: sulla posizione attuale eureka:apply-machine-billing-payer
     * (ogni notte alle 03:15) lo rimetterebbe com'e' su Eureka.
     */
    public function avvisoPaganteEureka(?string $sceltoId): ?string
    {
        $eureka = $this->paganteEureka();

        if (! $eureka || $eureka->id === $sceltoId) {
            return null;
        }

        return 'Su Eureka questa consegna è intestata a '.DisplayName::customerOption($eureka).'.'
            .($this->removed_at === null ? ' Stanotte il CRM rimetterà quello: per cambiarlo davvero, correggilo su Eureka.' : '');
    }

    /** Cambia chi pagava: sulla posizione attuale passa dalla macchina, che ne tiene la copia. */
    public function cambiaPagante(?Customer $pagante): void
    {
        $id = $pagante && $pagante->id !== $this->customer_id ? $pagante->id : null;

        if ($this->removed_at === null && $this->machineUnit) {
            $this->machineUnit->update(['billing_customer_id' => $id]);
            $this->refresh();

            return;
        }

        $this->update(['billing_customer_id' => $id]);
    }
}
