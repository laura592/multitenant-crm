<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Deadline extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_ASSICURAZIONE = 'assicurazione';
    public const TYPE_REVISIONE = 'revisione';
    public const TYPE_POLIZZA_RCT = 'polizza_rct';
    public const TYPE_MANUTENZIONE_ORDINARIA = 'manutenzione_ordinaria';
    public const TYPE_LICENZA = 'licenza';
    public const TYPE_CONTRATTO = 'contratto';
    public const TYPE_ALTRO = 'altro';

    protected $fillable = [
        'tenant_id',
        'deadlinable_type',
        'deadlinable_id',
        'type',
        'due_date',
        'reminder_days_before',
        'status',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'reminder_days_before' => 'integer',
    ];

    protected $attributes = [
        'reminder_days_before' => 30,
        'status' => 'attiva',
    ];

    public function deadlinable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUrgent(): bool
    {
        return $this->status === 'attiva'
            && now()->diffInDays($this->due_date, false) <= $this->reminder_days_before;
    }
}
