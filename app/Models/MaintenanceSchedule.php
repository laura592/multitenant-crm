<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceSchedule extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_MANUTENZIONE = 'manutenzione';

    public const TYPE_LAVAGGIO = 'lavaggio';

    public const STATUS_ATTIVO = 'attivo';

    public const STATUS_CHIUSO = 'chiuso';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'type',
        'status',
        'comodato_macchina_id',
        'frequency',
        'frequency_days',
        'last_service_report_id',
        'last_lavaggio_id',
        'next_due_date',
        'notes',
    ];

    protected $casts = [
        'next_due_date' => 'date',
    ];

    protected const FREQUENCY_MONTHS = [
        'mensile' => 1,
        'trimestrale' => 3,
        'semestrale' => 6,
        'annuale' => 12,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function comodatoMacchina(): BelongsTo
    {
        return $this->belongsTo(ComodatoMacchina::class);
    }

    public function lastServiceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class, 'last_service_report_id');
    }

    public function lastLavaggio(): BelongsTo
    {
        return $this->belongsTo(Lavaggio::class, 'last_lavaggio_id');
    }

    public function lavaggi(): HasMany
    {
        return $this->hasMany(Lavaggio::class);
    }

    /**
     * Da chiamare quando si chiude un ServiceReport di manutenzione collegato
     * a questo piano (docs/architecture.md §13.1).
     */
    public function markServiced(ServiceReport $report): void
    {
        $months = self::FREQUENCY_MONTHS[$this->frequency] ?? 1;

        $this->update([
            'last_service_report_id' => $report->id,
            'next_due_date' => $report->intervention_date->copy()->addMonths($months),
        ]);
    }

    /**
     * Ricalcola la scadenza di un piano di tipo lavaggio dall'ultimo lavaggio
     * registrato (per data, non per data di inserimento) + frequency_days.
     * Usa il MAX tra tutti i lavaggi collegati, non solo quello appena
     * salvato, per restare corretta anche modificando/cancellando lavaggi
     * storici (era la stessa logica prima su Customer::recalculateLavaggioNextDue()).
     */
    public function recalculateLavaggioNextDue(): void
    {
        $last = $this->lavaggi()->orderByDesc('data')->first();

        if (! $last) {
            return;
        }

        // "Ultimo lavaggio" va aggiornato sempre, anche sui piani "a
        // chiamata" (senza frequency_days): solo la prossima scadenza
        // automatica non ha senso senza una cadenza fissa.
        $this->update([
            'last_lavaggio_id' => $last->id,
            'next_due_date' => $this->frequency_days ? $last->data->copy()->addDays($this->frequency_days) : null,
        ]);
    }
}
