<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class MaintenanceSchedule extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_MANUTENZIONE = 'manutenzione';

    public const TYPE_LAVAGGIO = 'lavaggio';

    public const STATUS_ATTIVO = 'attivo';

    public const STATUS_CHIUSO = 'chiuso';

    public const BEVERAGE_BIRRA = 'birra';

    public const BEVERAGE_ACQUA = 'acqua';

    public const BEVERAGE_VINO = 'vino';

    public const BEVERAGE_BIBITE = 'bibite';

    public const BEVERAGE_SELZ = 'selz';

    /**
     * Cadenza standard per tipo di impianto, usata come default sul form e
     * dalla bulk action di correzione in MaintenanceScheduleResource. Vino,
     * bibite e selz sono di norma "a chiamata" (nessuna cadenza fissa),
     * quindi non compaiono qui.
     */
    public const STANDARD_FREQUENCY_DAYS = [
        self::BEVERAGE_BIRRA => 30,
    ];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'type',
        'status',
        'machine_unit_id',
        'beverage_type',
        'lines_count',
        'frequency',
        'frequency_days',
        'filter_validity_days',
        'last_service_report_id',
        'last_lavaggio_id',
        'last_filter_change_id',
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

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    public function lastServiceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class, 'last_service_report_id');
    }

    public function lastLavaggio(): BelongsTo
    {
        return $this->belongsTo(Lavaggio::class, 'last_lavaggio_id');
    }

    public function lastFilterChange(): BelongsTo
    {
        return $this->belongsTo(Lavaggio::class, 'last_filter_change_id');
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
     * ServiceReport non ha un maintenance_schedule_id esplicito (a differenza di
     * Lavaggio): il collegamento e' inferito da customer_id+machine_unit_id, per
     * non richiedere al tecnico una selezione manuale extra su ogni rapportino di
     * manutenzione. Va richiamata ad ogni salvataggio/cancellazione di un
     * ServiceReport per quella macchina (vedi ServiceReport::syncMaintenanceSchedule()),
     * usando il MAX per data intervento tra tutti i rapportini collegati — stessa
     * logica di recalculateLavaggioNextDue(), per restare corretta anche
     * modificando/cancellando rapportini storici.
     */
    public function recalculateFromServiceReports(): void
    {
        if ($this->type !== self::TYPE_MANUTENZIONE || ! $this->machine_unit_id) {
            return;
        }

        $last = ServiceReport::query()
            ->where('customer_id', $this->customer_id)
            ->where('machine_unit_id', $this->machine_unit_id)
            ->where('intervention_type', ServiceReport::TYPE_MANUTENZIONE_ORDINARIA)
            ->whereIn('status', ServiceReport::CLOSED_STATUSES)
            ->orderByDesc('intervention_date')
            ->first();

        if ($last) {
            $this->markServiced($last);
        }
    }

    /**
     * Ricalcola la scadenza di un piano di tipo lavaggio dall'ultimo lavaggio
     * registrato (per data, non per data di inserimento). Usa il MAX tra
     * tutti i lavaggi collegati, non solo quello appena salvato, per restare
     * corretta anche modificando/cancellando lavaggi storici (era la stessa
     * logica prima su Customer::recalculateLavaggioNextDue()).
     */
    public function recalculateLavaggioNextDue(): void
    {
        $last = $this->lavaggi()->orderByDesc('data')->first();

        if (! $last) {
            return;
        }

        // "Ultimo lavaggio" va aggiornato sempre, anche sui piani "a
        // chiamata" (senza frequency_days/filtro): solo la prossima scadenza
        // automatica non ha senso senza una cadenza fissa.
        $update = ['last_lavaggio_id' => $last->id];

        if ($this->beverage_type === self::BEVERAGE_ACQUA) {
            $lastFilterChange = $this->lavaggi()->where('filtro_sostituito', true)->orderByDesc('data')->first();

            $update['last_filter_change_id'] = $lastFilterChange?->id;
            $update['next_due_date'] = $lastFilterChange ? $this->nextAcquaDueDate($lastFilterChange) : null;
        } else {
            $update['next_due_date'] = $this->frequency_days ? $last->data->copy()->addDays($this->frequency_days) : null;
        }

        $this->update($update);
    }

    /**
     * Scadenza di un piano acqua: sanificazione ogni 4 mesi in ogni caso, a
     * meno che il filtro non si esaurisca prima (filter_validity_days piu'
     * stringente dei 4 mesi).
     */
    private function nextAcquaDueDate(Lavaggio $lastFilterChange): Carbon
    {
        $sanificazione = $lastFilterChange->data->copy()->addMonths(4);

        if (! $this->filter_validity_days) {
            return $sanificazione;
        }

        $filterExpiry = $lastFilterChange->data->copy()->addDays($this->filter_validity_days);

        return $filterExpiry->lessThan($sanificazione) ? $filterExpiry : $sanificazione;
    }
}
