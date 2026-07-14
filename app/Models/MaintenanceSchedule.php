<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MaintenanceSchedule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'comodato_macchina_id',
        'frequency',
        'last_service_report_id',
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

    public function deadlines(): MorphMany
    {
        return $this->morphMany(Deadline::class, 'deadlinable');
    }

    protected static function booted(): void
    {
        // Tiene sempre sincronizzata un'unica Deadline per il prossimo
        // appuntamento di manutenzione, cosi confluisce nel tableau unificato
        // insieme alle scadenze di automezzi/tenant (docs/architecture.md §13.2).
        static::saved(function (self $schedule) {
            $schedule->deadlines()->updateOrCreate(
                ['type' => Deadline::TYPE_MANUTENZIONE_ORDINARIA],
                ['tenant_id' => $schedule->tenant_id, 'due_date' => $schedule->next_due_date, 'status' => 'attiva']
            );
        });
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
}
