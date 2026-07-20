<?php

namespace App\Models;

use App\Jobs\SyncAppointmentToGoogle;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_PIANIFICATO = 'pianificato';

    public const STATUS_CONFERMATO = 'confermato';

    public const STATUS_IN_CORSO = 'in_corso';

    public const STATUS_COMPLETATO = 'completato';

    public const STATUS_ANNULLATO = 'annullato';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'technician_id',
        'comodato_macchina_id',
        'deadline_id',
        'service_report_id',
        'title',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'google_event_id',
        'google_synced_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PIANIFICATO,
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'google_synced_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function comodatoMacchina(): BelongsTo
    {
        return $this->belongsTo(ComodatoMacchina::class);
    }

    public function deadline(): BelongsTo
    {
        return $this->belongsTo(Deadline::class);
    }

    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    protected static function booted(): void
    {
        // Tiene sincronizzato il calendario Google secondario del tecnico
        // (docs/architecture.md §15.2). Si passano id/valori primitivi (non il
        // model) perche' al momento dell'elaborazione in coda la riga puo'
        // essere gia' stata cancellata — SerializesModels fallirebbe nel
        // ri-risolverla. Il job stesso salta se il tecnico non ha collegato un
        // account Google.
        static::saved(fn (self $appointment) => SyncAppointmentToGoogle::dispatch(
            $appointment->id,
            $appointment->technician_id,
            $appointment->google_event_id,
        ));

        static::deleted(fn (self $appointment) => SyncAppointmentToGoogle::dispatch(
            null,
            $appointment->technician_id,
            $appointment->google_event_id,
        ));
    }
}