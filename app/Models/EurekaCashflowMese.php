<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un mese di cash flow prospettico: quanto entra, quanto esce, cosa resta.
 *
 * Le componenti restano separate perche' non hanno lo stesso peso. Una
 * scadenza fattura (FTC/FTF) e' un impegno gia' preso; un ordine o una bolla
 * (OC/BC, OF/BF) e' merce che deve ancora diventare fattura. Sommarle in un
 * numero solo farebbe sembrare certo un incasso che non lo e'.
 */
class EurekaCashflowMese extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'eureka_cashflow_mesi';

    protected $fillable = [
        'tenant_id', 'anno', 'mese', 'entrate', 'uscite',
        'entrate_ftc', 'entrate_oc', 'entrate_bc',
        'uscite_ftf', 'uscite_of', 'uscite_bf',
        'saldo_mese', 'saldo_progressivo',
    ];

    protected $casts = [
        'anno' => 'integer',
        'mese' => 'integer',
        'entrate' => 'decimal:2',
        'uscite' => 'decimal:2',
        'entrate_ftc' => 'decimal:2',
        'entrate_oc' => 'decimal:2',
        'entrate_bc' => 'decimal:2',
        'uscite_ftf' => 'decimal:2',
        'uscite_of' => 'decimal:2',
        'uscite_bf' => 'decimal:2',
        'saldo_mese' => 'decimal:2',
        'saldo_progressivo' => 'decimal:2',
    ];

    /** La parte di entrate gia' fatturata: l'unica su cui si puo' contare. */
    public function entrateCerte(): float
    {
        return (float) $this->entrate_ftc;
    }

    /** Ordini e bolle: merce da consegnare o da fatturare, non ancora incasso. */
    public function entrateDaFatturare(): float
    {
        return (float) $this->entrate_oc + (float) $this->entrate_bc;
    }

    public function inizioMese(): Carbon
    {
        return Carbon::create($this->anno, $this->mese, 1)->startOfDay();
    }

    public function etichetta(): string
    {
        return ucfirst(mb_substr($this->inizioMese()->translatedFormat('F'), 0, 3)).' '.$this->anno;
    }

    /** Il mese in cui siamo: quello da cui il futuro comincia davvero. */
    public function eFuturo(): bool
    {
        return $this->inizioMese()->gte(now()->startOfMonth());
    }
}
