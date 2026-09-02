<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Il saldo che Eureka calcola per un'anagrafica, tenuto accanto alle partite
 * che quel saldo dovrebbe comporre.
 *
 * Esiste per rispondere a una domanda sola: le nostre partite raccontano la
 * stessa storia del gestionale? Quando i due numeri divergono non e' detto
 * che sia colpa nostra — le partite non vedono il portafoglio RiBa, per
 * esempio — ma finora la divergenza si scopriva solo aprendo Eureka a mano.
 */
class EurekaSaldoAnagrafica extends Model
{
    use BelongsToTenant, HasUuids;

    /** Sotto questa soglia la differenza e' arrotondamento, non un problema. */
    public const TOLLERANZA = 0.01;

    protected $table = 'eureka_saldi_anagrafiche';

    protected $fillable = [
        'tenant_id', 'tipo', 'gestionale_code', 'ragione_sociale', 'saldo',
    ];

    protected $casts = [
        'saldo' => 'decimal:2',
    ];
}
