<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComodatoMacchina extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'comodato_macchine';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'nome_macchina',
        'costo_macchina',
        'costo_attrezzatura',
        'anni_ammortamento',
        'prezzo_annuale_consumabili',
        'costi_manutenzione_annui',
        'costo_caffe_per_battitura',
        'erogazioni_annuali_minime',
        'erogazioni_previste_annue',
        'canone_fisso_annuale',
        'margine_percentuale',
        'note',
    ];

    protected $casts = [
        'costo_macchina' => 'decimal:2',
        'costo_attrezzatura' => 'decimal:2',
        'prezzo_annuale_consumabili' => 'decimal:2',
        'costi_manutenzione_annui' => 'decimal:2',
        'costo_caffe_per_battitura' => 'decimal:4',
        'canone_fisso_annuale' => 'decimal:2',
        'margine_percentuale' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function getCostoTotaleInvestimentoAttribute(): float
    {
        return $this->costo_macchina + $this->costo_attrezzatura;
    }

    public function getCostoAmmortizzatoAnnuoAttribute(): float
    {
        return $this->costo_totale_investimento / $this->anni_ammortamento;
    }

    public function getCostoPerBattituraAttribute(): ?float
    {
        if (! $this->erogazioni_previste_annue || $this->erogazioni_previste_annue <= 0) {
            return null;
        }

        return $this->calcolaCostoPerErogazione($this->erogazioni_previste_annue)['costo_per_erogazione'];
    }

    public function calcolaCostoPerErogazione(?int $erogazioniAnnueEffettive = null): array
    {
        if (is_null($erogazioniAnnueEffettive)) {
            $erogazioniAnnueEffettive = $this->erogazioni_previste_annue;
        }

        if (! $erogazioniAnnueEffettive || $erogazioniAnnueEffettive <= 0) {
            return [
                'errore' => 'Numero erogazioni annue deve essere maggiore di 0',
                'costo_per_erogazione' => 0,
            ];
        }

        $ammortamentoAnnuo = $this->costo_totale_investimento / $this->anni_ammortamento;

        $costoAmmortamentoPerErogazione = $ammortamentoAnnuo / $erogazioniAnnueEffettive;
        $costoManutenzionePerErogazione = $this->costi_manutenzione_annui / $erogazioniAnnueEffettive;
        $costoConsumabiliPerErogazione = $this->prezzo_annuale_consumabili / $erogazioniAnnueEffettive;
        $costoCaffePerErogazione = $this->costo_caffe_per_battitura;

        $costoPerErogazione = $costoAmmortamentoPerErogazione
            + $costoManutenzionePerErogazione
            + $costoConsumabiliPerErogazione
            + $costoCaffePerErogazione;

        $canoneFissoPerErogazione = 0;
        if ($this->canone_fisso_annuale > 0) {
            $canoneFissoPerErogazione = $this->canone_fisso_annuale / $erogazioniAnnueEffettive;
            $costoPerErogazione += $canoneFissoPerErogazione;
        }

        if ($this->margine_percentuale > 0) {
            $costoPerErogazione += $costoPerErogazione * ($this->margine_percentuale / 100);
        }

        return [
            'costo_per_erogazione' => round($costoPerErogazione, 4),
            'dettaglio' => [
                'ammortamento_per_erogazione' => round($costoAmmortamentoPerErogazione, 4),
                'manutenzione_per_erogazione' => round($costoManutenzionePerErogazione, 4),
                'consumabili_per_erogazione' => round($costoConsumabiliPerErogazione, 4),
                'caffe_per_erogazione' => round($costoCaffePerErogazione, 4),
                'canone_fisso_per_erogazione' => round($canoneFissoPerErogazione, 4),
                'margine_applicato' => $this->margine_percentuale,
            ],
        ];
    }
}
