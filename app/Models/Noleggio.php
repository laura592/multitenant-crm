<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\Noleggio\CanoneOperativo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un noleggio operativo: macchina di Alex, canone mensile al cliente.
 *
 * Il canone non si scrive a mano: lo calcola CanoneOperativo dagli
 * ingredienti salvati qui, e si ricongela a ogni salvataggio. Chi guarda un
 * contratto di due anni fa deve poter rifare il conto, non solo leggerne il
 * risultato.
 */
class Noleggio extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail, SoftDeletes;

    protected $table = 'noleggi';

    public const STATO_BOZZA = 'bozza';

    public const STATO_ATTIVO = 'attivo';

    public const STATO_CHIUSO = 'chiuso';

    protected $fillable = [
        'tenant_id', 'customer_id', 'machine_unit_id', 'quote_id', 'descrizione',
        'listino', 'costo', 'mesi', 'margine', 'detergenti_mese', 'ricarico_detergenti',
        'valore_residuo', 'full_service_percentuale',
        'quota_macchina', 'quota_servizio', 'quota_detergenti', 'canone', 'mese_pareggio',
        'data_inizio', 'stato', 'note',
    ];

    protected $casts = [
        'listino' => 'decimal:2', 'costo' => 'decimal:2', 'mesi' => 'integer',
        'margine' => 'decimal:2', 'detergenti_mese' => 'decimal:2', 'ricarico_detergenti' => 'decimal:2',
        'valore_residuo' => 'decimal:2', 'full_service_percentuale' => 'decimal:2',
        'quota_macchina' => 'decimal:2', 'quota_servizio' => 'decimal:2',
        'quota_detergenti' => 'decimal:2', 'canone' => 'decimal:2',
        'mese_pareggio' => 'integer', 'data_inizio' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $noleggio) {
            $r = $noleggio->ricalcola();

            $noleggio->quota_macchina = $r->quotaMacchina;
            $noleggio->quota_servizio = $r->quotaServizio;
            $noleggio->quota_detergenti = $r->quotaDetergenti;
            $noleggio->canone = $r->canone;
            $noleggio->mese_pareggio = $r->mesePareggio;
        });
    }

    public function ricalcola(): CanoneOperativo
    {
        return CanoneOperativo::calcola(
            costoMacchina: (float) $this->costo,
            listinoMacchina: (float) $this->listino,
            mesi: (int) $this->mesi,
            valoreResiduo: (float) $this->valore_residuo,
            margine: (float) $this->margine / 100,
            detergentiMese: (float) $this->detergenti_mese,
            fullServiceAnnuo: (float) $this->full_service_percentuale / 100,
            ricaricoDetergenti: (float) $this->ricarico_detergenti / 100,
        );
    }

    /** Quanto resta scoperto se il cliente disdice a quel mese. */
    public function scopertoAl(int $mese): float
    {
        return $this->ricalcola()->scopertoSeDisdettaAl($mese);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public static function statiLabels(): array
    {
        return [
            self::STATO_BOZZA => 'Bozza',
            self::STATO_ATTIVO => 'Attivo',
            self::STATO_CHIUSO => 'Chiuso',
        ];
    }
}
