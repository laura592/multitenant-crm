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

    public const TYPE_BOLLO = 'bollo';

    public const TYPE_REVISIONE = 'revisione';

    public const TYPE_POLIZZA_RCT = 'polizza_rct';

    public const TYPE_LICENZA = 'licenza';

    public const TYPE_CONTRATTO = 'contratto';

    public const TYPE_ALTRO = 'altro';

    public const STATUS_ATTIVA = 'attiva';

    public const STATUS_SCADUTA = 'scaduta';

    public const STATUS_RINNOVATA = 'rinnovata';

    protected $fillable = [
        'tenant_id',
        'deadlinable_type',
        'deadlinable_id',
        'type',
        'policy_number',
        'due_date',
        'amount',
        'paid_at',
        'reminder_days_before',
        'status',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'date',
        'amount' => 'decimal:2',
        'reminder_days_before' => 'integer',
    ];

    protected $attributes = [
        'reminder_days_before' => 30,
        'status' => self::STATUS_ATTIVA,
    ];

    public static function typeLabels(): array
    {
        return [
            self::TYPE_ASSICURAZIONE => 'Assicurazione',
            self::TYPE_BOLLO => 'Bollo',
            self::TYPE_REVISIONE => 'Revisione',
            self::TYPE_POLIZZA_RCT => 'Polizza RCT',
            self::TYPE_LICENZA => 'Licenza',
            self::TYPE_CONTRATTO => 'Contratto',
            self::TYPE_ALTRO => 'Altro',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_ATTIVA => 'Attiva',
            self::STATUS_SCADUTA => 'Scaduta',
            self::STATUS_RINNOVATA => 'Rinnovata',
        ];
    }

    public static function statusColors(): array
    {
        return [
            self::STATUS_ATTIVA => 'gray',
            self::STATUS_SCADUTA => 'danger',
            self::STATUS_RINNOVATA => 'success',
        ];
    }

    public function deadlinable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUrgent(): bool
    {
        return $this->status === self::STATUS_ATTIVA
            && now()->diffInDays($this->due_date, false) <= $this->reminder_days_before;
    }

    /**
     * Colore badge/testo per due_date, prima reimplementato in modo
     * identico in DeadlineResource, HasDeadlinesTable e
     * UpcomingDeadlinesWidget.
     */
    public function dueDateColor(): string
    {
        return match (true) {
            $this->due_date->isPast() => 'danger',
            $this->isUrgent() => 'warning',
            default => 'success',
        };
    }

    /**
     * Chiude questa occorrenza (importo/data pagamento, stato "rinnovata") e
     * ne crea una nuova identica con la prossima scadenza, invece di
     * sovrascrivere due_date sulla stessa riga: cosi' lo storico
     * costi/pagamenti resta leggibile come sequenza di righe passate.
     */
    public function renew(array $data): self
    {
        $this->forceFill([
            'amount' => $data['amount'] ?? $this->amount,
            'paid_at' => $data['paid_at'] ?? $this->paid_at,
            'status' => self::STATUS_RINNOVATA,
        ])->save();

        return self::create([
            'tenant_id' => $this->tenant_id,
            'deadlinable_type' => $this->deadlinable_type,
            'deadlinable_id' => $this->deadlinable_id,
            'type' => $this->type,
            'due_date' => $data['due_date'],
            'reminder_days_before' => $this->reminder_days_before,
            'status' => self::STATUS_ATTIVA,
        ]);
    }
}
