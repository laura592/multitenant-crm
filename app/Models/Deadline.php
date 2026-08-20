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
        'reminder_days_before',
        'status',
    ];

    protected $casts = [
        'due_date' => 'date',
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

    /**
     * Periodicita' standard di legge per tipo, in anni: usata solo per
     * precompilare la data nel modal "Rinnova" (resta sempre modificabile a
     * mano, es. bollo pagato per pochi mesi o revisione anticipata).
     */
    public static function renewalPeriodsInYears(): array
    {
        return [
            self::TYPE_ASSICURAZIONE => 1,
            self::TYPE_BOLLO => 1,
            self::TYPE_REVISIONE => 2,
            self::TYPE_POLIZZA_RCT => 1,
            self::TYPE_LICENZA => 1,
            self::TYPE_CONTRATTO => 1,
            self::TYPE_ALTRO => 1,
        ];
    }

    public function suggestedRenewalDate(): \Illuminate\Support\Carbon
    {
        $years = self::renewalPeriodsInYears()[$this->type] ?? 1;

        return $this->due_date->copy()->addYears($years);
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
    /**
     * Stessa logica della colonna "Collegata a" in DeadlineResource, estratta
     * qui per riuso nel digest email settimanale delle scadenze.
     */
    public function relatedLabel(): string
    {
        return match (true) {
            $this->deadlinable instanceof Vehicle => $this->deadlinable->assigned_user_id
                ? "{$this->deadlinable->plate} — personale ({$this->deadlinable->assignedUser->name})"
                : "{$this->deadlinable->plate} — aziendale",
            $this->deadlinable instanceof Tenant => "Azienda {$this->deadlinable->name}",
            default => class_basename($this->deadlinable_type),
        };
    }

    public function dueDateColor(): string
    {
        return match (true) {
            $this->due_date->isPast() => 'danger',
            $this->isUrgent() => 'warning',
            default => 'success',
        };
    }

    /**
     * Chiude questa occorrenza (stato "rinnovata") e ne crea una nuova
     * identica con la prossima scadenza, invece di sovrascrivere due_date
     * sulla stessa riga: cosi' lo storico delle scadenze passate resta
     * leggibile come sequenza di righe invece di essere perso ad ogni
     * rinnovo.
     */
    public function renew(array $data): self
    {
        $this->forceFill([
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
