<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'clock_in',
        'clock_out',
        'source',
        'entered_by_user_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    public function isOpen(): bool
    {
        return is_null($this->clock_out);
    }

    /**
     * Ore lavorate in questo turno. Lo straordinario NON si salva qui: si calcola
     * in aggregazione giornaliera nel riepilogo mensile (docs/architecture.md §12.1).
     */
    public function getWorkedHoursAttribute(): ?float
    {
        if (! $this->clock_out) {
            return null;
        }

        return round($this->clock_in->diffInMinutes($this->clock_out) / 60, 2);
    }
}
