<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lavaggio periodico di una macchina presso un cliente (es. "5 vie + apertura",
 * "chiusura stagionale"): registro dei singoli interventi, area separata
 * dagli interventi tecnici veri e propri (ServiceReport) perche' i lavaggi
 * hanno una natura diversa (pulizia, non riparazione/manutenzione). La
 * cadenza/scadenza vive sul MaintenanceSchedule di tipo 'lavaggio' collegato
 * (maintenance_schedule_id), non piu' sul Customer.
 */
class Lavaggio extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'lavaggi';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'machine_unit_id',
        'maintenance_schedule_id',
        'data',
        'descrizione',
        'note',
    ];

    protected $casts = [
        'data' => 'date',
    ];

    // Proprieta' reale (non un attributo Eloquent): dichiararla esplicitamente
    // evita che l'assegnazione finisca nel magic __set() di Eloquent, che la
    // tratterebbe come una colonna da scrivere e romperebbe la UPDATE.
    public ?string $previousMaintenanceScheduleId = null;

    protected static function booted(): void
    {
        // La prossima scadenza lavaggio (MaintenanceSchedule::next_due_date)
        // e' calcolata dall'ultimo lavaggio registrato: va ricalcolata ogni
        // volta che un lavaggio viene salvato o cancellato, non solo alla
        // creazione, altrimenti modificare/eliminare un lavaggio storico
        // lascerebbe la scadenza disallineata.
        static::updating(function (self $lavaggio) {
            $lavaggio->previousMaintenanceScheduleId = $lavaggio->getOriginal('maintenance_schedule_id');
        });

        static::saved(function (self $lavaggio) {
            // Query fresca invece della relation `maintenanceSchedule`: se il
            // record era gia' stato caricato con la relazione in cache (es.
            // dal form di Filament) e poi si cambia maintenance_schedule_id,
            // Eloquent non invalida la relazione BelongsTo gia' risolta e
            // ricalcolerebbe il piano sbagliato (quello vecchio).
            if ($lavaggio->maintenance_schedule_id) {
                MaintenanceSchedule::find($lavaggio->maintenance_schedule_id)?->recalculateLavaggioNextDue();
            }

            // Se il lavaggio e' stato spostato su un altro piano (es. cambio
            // cliente), anche il piano precedente va ricalcolato: altrimenti
            // resterebbe con last_lavaggio_id/next_due_date basati su un
            // lavaggio che non gli appartiene piu'.
            $previousScheduleId = $lavaggio->previousMaintenanceScheduleId ?? null;

            if ($previousScheduleId && $previousScheduleId !== $lavaggio->maintenance_schedule_id) {
                MaintenanceSchedule::find($previousScheduleId)?->recalculateLavaggioNextDue();
            }
        });

        static::deleted(function (self $lavaggio) {
            if ($lavaggio->maintenance_schedule_id) {
                MaintenanceSchedule::find($lavaggio->maintenance_schedule_id)?->recalculateLavaggioNextDue();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    public function maintenanceSchedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class);
    }
}
