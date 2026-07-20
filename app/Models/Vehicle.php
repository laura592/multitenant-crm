<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vehicle extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'plate',
        'brand',
        'model',
        'year',
        'insurance_due_date',
        'revision_due_date',
        'assigned_user_id',
        'notes',
    ];

    protected $casts = [
        'insurance_due_date' => 'date',
        'revision_due_date' => 'date',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function deadlines(): MorphMany
    {
        return $this->morphMany(Deadline::class, 'deadlinable');
    }

    protected static function booted(): void
    {
        // Tiene sincronizzate le Deadline di assicurazione/revisione con le date
        // inserite sul veicolo, stesso pattern di MaintenanceSchedule (§13.2):
        // niente scadenza dimenticata quando si registra un nuovo automezzo.
        static::saved(function (self $vehicle) {
            $vehicle->syncDeadline(Deadline::TYPE_ASSICURAZIONE, $vehicle->insurance_due_date);
            $vehicle->syncDeadline(Deadline::TYPE_REVISIONE, $vehicle->revision_due_date);
        });
    }

    private function syncDeadline(string $type, ?\Carbon\Carbon $dueDate): void
    {
        if (! $dueDate) {
            $this->deadlines()->where('type', $type)->delete();

            return;
        }

        $this->deadlines()->updateOrCreate(
            ['type' => $type],
            ['tenant_id' => $this->tenant_id, 'due_date' => $dueDate, 'status' => Deadline::STATUS_ATTIVA]
        );
    }
}
