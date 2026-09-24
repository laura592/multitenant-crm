<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\Presenze\Festivi;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    public const TYPE_FERIE = 'ferie';
    public const TYPE_PERMESSO = 'permesso';
    public const TYPE_MALATTIA = 'malattia';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'date_from',
        'date_to',
        'time_from',
        'time_to',
        'hours',
        'status',
        'requested_at',
        'approved_by_user_id',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'hours' => 'decimal:2',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'richiesto',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->requested_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function approve(User $approver): void
    {
        $this->update([
            'status' => 'approvato',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(User $approver): void
    {
        $this->update([
            'status' => 'rifiutato',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function getDaysAttribute(): int
    {
        return $this->daysWithin();
    }

    /**
     * Giorni della richiesta, eventualmente ritagliati su un periodo (il
     * riepilogo mensile non deve contare i giorni di una richiesta a cavallo
     * di due mesi due volte). Le ferie si scalano solo nei giorni lavorativi:
     * 8-18 settembre 2026 sono 9 giorni, non gli 11 di calendario. Neanche i
     * festivi sono ferie: o sono festa o sono lavorati (Festivi). La malattia
     * resta a giorni di calendario, come la conta l'INPS.
     */
    public function daysWithin(?\Carbon\CarbonInterface $from = null, ?\Carbon\CarbonInterface $to = null): int
    {
        $start = $from && $from->gt($this->date_from) ? $from->copy()->startOfDay() : $this->date_from->copy();
        $end = $to && $to->lt($this->date_to) ? $to->copy()->startOfDay() : $this->date_to->copy();

        if ($start->gt($end)) {
            return 0;
        }

        if ($this->type !== self::TYPE_FERIE) {
            return (int) $start->diffInDays($end) + 1;
        }

        $days = 0;
        for ($day = $start; $day->lte($end); $day->addDay()) {
            if (! Festivi::isNonLavorativo($day)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Usato da notifiche in-app, mail e liste per non ripetere in tre posti
     * diversi la stessa logica "stesso giorno? mostra orario, altrimenti
     * intervallo di date".
     */
    public function periodLabel(): string
    {
        if ($this->type === self::TYPE_PERMESSO && $this->time_from && $this->time_to) {
            $from = \Carbon\Carbon::parse($this->time_from)->format('H:i');
            $to = \Carbon\Carbon::parse($this->time_to)->format('H:i');

            return "{$this->date_from->format('d/m/Y')}, {$from} - {$to}";
        }

        return $this->date_from->isSameDay($this->date_to)
            ? $this->date_from->format('d/m/Y')
            : "{$this->date_from->format('d/m/Y')} - {$this->date_to->format('d/m/Y')}";
    }
}
