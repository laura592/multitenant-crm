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
        'assigned_user_id',
        'notes',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function deadlines(): MorphMany
    {
        return $this->morphMany(Deadline::class, 'deadlinable');
    }

    /**
     * Scadenza attiva di un tipo (assicurazione/bollo/revisione) per la
     * vista lista/scheda del veicolo: al piu' una per tipo, le altre sono
     * storico (status "rinnovata"), vedi Deadline::renew().
     */
    public function activeDeadline(string $type): ?Deadline
    {
        $deadline = $this->deadlines
            ->where('type', $type)
            ->where('status', Deadline::STATUS_ATTIVA)
            ->sortByDesc('due_date')
            ->first();

        return $deadline instanceof Deadline ? $deadline : null;
    }
}
