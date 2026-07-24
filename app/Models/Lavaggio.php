<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lavaggio periodico di una macchina presso un cliente (es. "5 vie + apertura",
 * "chiusura stagionale"): area separata dagli interventi tecnici veri e
 * propri (ServiceReport), su richiesta esplicita perche' i lavaggi hanno una
 * cadenza e una natura diversa (pulizia, non riparazione/manutenzione).
 */
class Lavaggio extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'lavaggi';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'machine_unit_id',
        'data',
        'descrizione',
        'note',
    ];

    protected $casts = [
        'data' => 'date',
    ];

    protected static function booted(): void
    {
        // La prossima scadenza lavaggio del cliente (Customer::lavaggio_next_due_date)
        // e' calcolata dall'ultimo lavaggio registrato: va ricalcolata ogni
        // volta che un lavaggio viene salvato o cancellato, non solo alla
        // creazione, altrimenti modificare/eliminare un lavaggio storico
        // lascerebbe la scadenza disallineata.
        static::saved(fn (self $lavaggio) => $lavaggio->customer?->recalculateLavaggioNextDue());
        static::deleted(fn (self $lavaggio) => $lavaggio->customer?->recalculateLavaggioNextDue());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }
}
