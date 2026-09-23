<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\DisplayName;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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

    /**
     * Come si legge chi pagava in questo periodo: il cliente stesso, un
     * altro, oppure "come il cliente" quando sulla macchina non e' stato
     * deciso e vale il pagante dell'anagrafica.
     */
    public function paganteInParole(): string
    {
        if (! $this->customer_id) {
            return '—';
        }

        if ($this->billing_customer_id === $this->customer_id) {
            return 'il cliente stesso';
        }

        if ($this->billingCustomer) {
            return DisplayName::customerOption($this->billingCustomer);
        }

        $delCliente = $this->customer?->billingCustomer;

        return $delCliente
            ? 'come il cliente ('.DisplayName::titleCase($delCliente->company_name).')'
            : 'il cliente';
    }

    /**
     * Cambia chi paga.
     *
     * Sulla posizione attuale, con una data: la macchina resta dov'e', ma da
     * quel giorno paga un altro. La riga si chiude e se ne apre una nuova
     * presso lo stesso cliente, cosi' lo storico dice chi pagava fino a
     * quando (22/09/2026). Senza data (o su una riga vecchia) e' una
     * correzione: era sbagliato dall'inizio, e si sovrascrive.
     */
    public function cambiaPagante(?Customer $pagante, ?\DateTimeInterface $dal = null): void
    {
        // Il cliente stesso e' una scelta valida: "paga lui, non chi paga
        // per lui" (Bar Miki, 23/09/2026).
        $id = $pagante?->id;

        if ($this->removed_at === null && $this->machineUnit) {
            if ($dal && Carbon::instance($dal)->startOfDay()->gt($this->placed_at->copy()->startOfDay())) {
                $this->machineUnit->moveTo($this->customer, 'Cambio pagante', $dal, $pagante, $this->eureka_billing_customer_code);

                return;
            }

            $this->machineUnit->update(['billing_customer_id' => $id]);
            $this->refresh();

            return;
        }

        $this->update(['billing_customer_id' => $id]);
    }
}
