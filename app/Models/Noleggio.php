<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\Noleggio\CanoneOperativo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'listino', 'sconto_acquisto', 'costo', 'mesi', 'base_consumo', 'margine',
        'ammortamento_base', 'ammortamento_mesi', 'detergenti_mese', 'ricarico_detergenti', 'caffe_mese', 'ricarico_caffe', 'caffe_kg_mese', 'detergenti_inclusi',
        'valore_residuo', 'full_service_percentuale',
        'quota_macchina', 'quota_servizio', 'quota_detergenti', 'quota_caffe', 'quota_consumabili', 'canone', 'mese_pareggio',
        'data_inizio', 'stato', 'note',
        'periodicita_fatturazione', 'modalita_pagamento', 'termini_pagamento',
    ];

    protected $casts = [
        'listino' => 'decimal:2', 'sconto_acquisto' => 'decimal:2', 'costo' => 'decimal:2', 'mesi' => 'integer',
        'margine' => 'decimal:2', 'detergenti_mese' => 'decimal:2', 'ricarico_detergenti' => 'decimal:2',
        'caffe_mese' => 'decimal:2', 'caffe_kg_mese' => 'decimal:2', 'ricarico_caffe' => 'decimal:2', 'quota_caffe' => 'decimal:2',
        'valore_residuo' => 'decimal:2', 'full_service_percentuale' => 'decimal:2',
        'quota_macchina' => 'decimal:2', 'quota_servizio' => 'decimal:2',
        'quota_detergenti' => 'decimal:2', 'quota_consumabili' => 'decimal:2', 'canone' => 'decimal:2',
        'mese_pareggio' => 'integer', 'data_inizio' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $noleggio) {
            // Lo sconto, se c'e', comanda sul costo: sono due modi di dire la
            // stessa cosa e il secondo si disallinea al primo aggiornamento.
            if ($noleggio->sconto_acquisto !== null && (float) $noleggio->listino > 0) {
                $noleggio->costo = round((float) $noleggio->listino * (1 - (float) $noleggio->sconto_acquisto / 100), 2);
            }

            $r = $noleggio->ricalcola();

            $noleggio->quota_macchina = $r->quotaMacchina;
            $noleggio->quota_servizio = $r->quotaServizio;
            $noleggio->quota_detergenti = $r->quotaDetergenti;
            $noleggio->quota_caffe = $r->quotaCaffe;
            $noleggio->quota_consumabili = $r->quotaConsumabili;
            $noleggio->canone = $r->canone;
            $noleggio->mese_pareggio = $r->mesePareggio;
        });
    }

    public function forniture(): HasMany
    {
        return $this->hasMany(NoleggioFornitura::class)->orderBy('ordine')->orderBy('voce');
    }

    /**
     * Le forniture a righe vincono sugli importi complessivi: se ce ne sono,
     * detergenti e caffe' si leggono da li'. I due campi piatti restano per i
     * noleggi scritti prima che le righe esistessero.
     */
    public function totaleForniture(string $gruppo): ?float
    {
        $righe = $this->relationLoaded('forniture') ? $this->forniture : $this->forniture()->get();
        $delGruppo = $righe->where('gruppo', $gruppo);

        return $delGruppo->isEmpty() ? null : (float) $delGruppo->sum('costo_mensile');
    }

    public function ricalcola(): CanoneOperativo
    {
        $detergenti = $this->totaleForniture(NoleggioFornitura::GRUPPO_DETERGENTI);
        $caffe = $this->totaleForniture(NoleggioFornitura::GRUPPO_CAFFE);
        $polveri = $this->totaleForniture(NoleggioFornitura::GRUPPO_POLVERI) ?? 0.0;
        // Bicchieri, palette, zucchero: hanno una voce loro nel canone, se no
        // il contratto li promette e il canone non li copre.
        $consumabili = $this->totaleForniture(NoleggioFornitura::GRUPPO_CONSUMABILI) ?? 0.0;

        return CanoneOperativo::calcola(
            costoMacchina: (float) $this->costo,
            listinoMacchina: (float) $this->listino,
            mesi: (int) $this->mesi,
            valoreResiduo: (float) $this->valore_residuo,
            margine: (float) $this->margine / 100,
            detergentiMese: $detergenti ?? (float) $this->detergenti_mese,
            fullServiceAnnuo: (float) $this->full_service_percentuale / 100,
            // Il ricarico e' gia' dentro ogni riga: applicarlo di nuovo lo
            // conterebbe due volte.
            ricaricoDetergenti: $detergenti !== null ? 0.0 : (float) $this->ricarico_detergenti / 100,
            caffeMese: ($caffe ?? (float) $this->caffe_mese) + $polveri,
            ricaricoCaffe: $caffe !== null ? 0.0 : (float) $this->ricarico_caffe / 100,
            valoreDaAmmortizzare: $this->ammortamento_base === 'listino'
                ? (float) $this->listino
                : (float) $this->costo,
            mesiAmmortamento: $this->ammortamento_mesi ? (int) $this->ammortamento_mesi : null,
            consumabiliMese: $consumabili,
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

    /** Lo storico degli invii al cliente. */
    public function emails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NoleggioEmail::class)->latest();
    }

    public static function statiLabels(): array
    {
        return [
            self::STATO_BOZZA => 'Bozza',
            self::STATO_ATTIVO => 'Attivo',
            self::STATO_CHIUSO => 'Chiuso',
        ];
    }

    /**
     * Condizioni di pagamento. Le etichette sono gia' scritte come vanno
     * lette nel contratto: una sola dicitura, uguale nel gestionale e sul
     * documento firmato, cosi' non si discute su cosa si era pattuito.
     */
    public static function periodicitaLabels(): array
    {
        return [
            'mensile' => 'mensile anticipata',
            'bimestrale' => 'bimestrale anticipata',
            'trimestrale' => 'trimestrale anticipata',
        ];
    }

    public static function modalitaPagamentoLabels(): array
    {
        return [
            'bonifico' => 'bonifico bancario',
            'sdd' => 'addebito diretto SEPA (SDD)',
            'riba' => 'ricevuta bancaria (RiBa)',
        ];
    }

    public static function terminiPagamentoLabels(): array
    {
        return [
            'vista' => 'rimessa diretta a vista fattura',
            '30_df' => '30 giorni data fattura',
            '30_fm' => '30 giorni fine mese',
            '60_fm' => '60 giorni fine mese',
        ];
    }
}
