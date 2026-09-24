<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\DisplayName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un lavoro deciso per un giorno preciso: l'ufficio lo mette in programma, il
 * tecnico lo trova nel suo giro (24/09/2026).
 *
 * Non va confuso con il piano (MaintenanceSchedule), che dice ogni quanto si
 * torna e calcola una scadenza, ne' con il rapportino (ServiceReport), che
 * dice cosa e' stato fatto. Questo sta in mezzo: e' l'impegno preso.
 *
 * Quando l'intervento viene eseguito si collega al rapportino che lo
 * documenta e passa a "fatto": da quel momento la storia la racconta il
 * rapportino, e questa riga resta solo come traccia di chi l'aveva deciso e
 * per quando.
 */
class InterventoProgrammato extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    protected $table = 'interventi_programmati';

    public const MOMENTO_GIORNATA = 'giornata';

    public const MOMENTO_MATTINA = 'mattina';

    public const MOMENTO_POMERIGGIO = 'pomeriggio';

    public const TIPO_CHIUSURA = 'chiusura';

    public const TIPO_APERTURA = 'apertura';

    public const TIPO_LAVAGGIO = 'lavaggio';

    public const TIPO_MANUTENZIONE = 'manutenzione';

    public const TIPO_INSTALLAZIONE = 'installazione';

    public const TIPO_RITIRO = 'ritiro';

    public const TIPO_ALTRO = 'altro';

    public const STATO_DA_FARE = 'da_fare';

    public const STATO_FATTO = 'fatto';

    public const STATO_ANNULLATO = 'annullato';

    protected $fillable = [
        'tenant_id',
        'data',
        'momento',
        'customer_id',
        'machine_unit_id',
        'maintenance_schedule_id',
        'technician_id',
        'tipo',
        'titolo',
        'note',
        'stato',
        'fatto_il',
        'service_report_id',
    ];

    protected $casts = [
        'data' => 'date',
        'fatto_il' => 'datetime',
    ];

    /** @return array<string, string> */
    public static function momenti(): array
    {
        return [
            self::MOMENTO_GIORNATA => 'In giornata',
            self::MOMENTO_MATTINA => 'Mattina',
            self::MOMENTO_POMERIGGIO => 'Pomeriggio',
        ];
    }

    /** @return array<string, string> */
    public static function tipi(): array
    {
        return [
            self::TIPO_CHIUSURA => 'Chiusura stagionale',
            self::TIPO_APERTURA => 'Apertura stagionale',
            self::TIPO_LAVAGGIO => 'Lavaggio',
            self::TIPO_MANUTENZIONE => 'Manutenzione',
            self::TIPO_INSTALLAZIONE => 'Installazione',
            self::TIPO_RITIRO => 'Ritiro',
            self::TIPO_ALTRO => 'Altro',
        ];
    }

    /** @return array<string, string> */
    public static function stati(): array
    {
        return [
            self::STATO_DA_FARE => 'Da fare',
            self::STATO_FATTO => 'Fatto',
            self::STATO_ANNULLATO => 'Annullato',
        ];
    }

    /**
     * Il tipo di rapportino che nasce da questo intervento. Chiusure,
     * aperture e lavaggi sono sanificazioni/lavaggi impianto; il resto ha il
     * suo corrispondente diretto.
     */
    public function tipoRapportino(): string
    {
        return match ($this->tipo) {
            self::TIPO_CHIUSURA, self::TIPO_APERTURA, self::TIPO_LAVAGGIO => ServiceReport::TYPE_SANIFICAZIONE,
            self::TIPO_INSTALLAZIONE => ServiceReport::TYPE_INSTALLAZIONE,
            self::TIPO_RITIRO => ServiceReport::TYPE_DISINSTALLAZIONE,
            default => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
        };
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    public function maintenanceSchedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    public function scopeDaFare(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_DA_FARE);
    }

    public function scopeDiTecnico(Builder $query, string $userId): Builder
    {
        return $query->where('technician_id', $userId);
    }

    /** Il giro di una giornata, nell'ordine in cui si legge: mattina, giornata, pomeriggio. */
    public function scopeDelGiorno(Builder $query, Carbon|string $giorno): Builder
    {
        return $this->scopeOrdinato($query->whereDate('data', $giorno));
    }

    public function scopeOrdinato(Builder $query): Builder
    {
        // CASE e non FIELD(): FIELD e' solo di MySQL, e questa riga finirebbe
        // dritta nell'elenco delle cose da riscrivere il giorno che si cambia
        // motore (vedi docs/prova-postgres.md).
        return $query
            ->orderBy('data')
            ->orderByRaw("CASE momento WHEN 'mattina' THEN 1 WHEN 'giornata' THEN 2 ELSE 3 END");
    }

    /** In ritardo: passato il giorno ed e' ancora da fare. */
    public function inRitardo(): bool
    {
        return $this->stato === self::STATO_DA_FARE
            && $this->data !== null
            && $this->data->isBefore(Carbon::today());
    }

    /**
     * Quello che si legge su una riga del giro: il cliente, o il titolo
     * scritto a mano quando il cliente non c'e' ("scrivere agenzia").
     */
    public function etichetta(): string
    {
        $cliente = DisplayName::titleCase($this->customer?->company_name);

        return $cliente ?: ($this->titolo ?: (self::tipi()[$this->tipo] ?? 'Intervento'));
    }

    /**
     * Segna l'intervento come fatto, eventualmente collegandolo al rapportino
     * che lo documenta. Non tocca il piano: a metterlo in pausa ci pensa il
     * lavaggio di chiusura, che e' il fatto vero (vedi Lavaggio::seguiLaStagione()).
     */
    public function segnaFatto(?ServiceReport $rapportino = null): void
    {
        $this->update([
            'stato' => self::STATO_FATTO,
            'fatto_il' => now(),
            'service_report_id' => $rapportino ? $rapportino->id : $this->service_report_id,
        ]);
    }
}
