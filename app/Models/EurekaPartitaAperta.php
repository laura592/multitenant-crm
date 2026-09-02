<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una partita aperta (fattura non ancora saldata) letta da Eureka.
 *
 * Fotografia ricaricata dal comando eureka:import-partite-aperte, non un
 * archivio storico: quando una fattura viene incassata sparisce da Eureka e
 * quindi anche da qui. Per questo non c'e' soft delete — non avrebbe senso
 * conservare una partita che il gestionale considera chiusa.
 */
class EurekaPartitaAperta extends Model
{
    use BelongsToTenant, HasUuids;

    public const TIPO_CLIENTE = 'cliente';

    public const TIPO_FORNITORE = 'fornitore';

    protected $table = 'eureka_partite_aperte';

    protected $fillable = [
        'tenant_id',
        'tipo',
        'gestionale_code',
        'customer_id',
        'ragione_sociale',
        'anno',
        'numero_fattura',
        'data_fattura',
        'data_scadenza',
        'tipo_pagamento',
        'saldo',
    ];

    protected $casts = [
        'data_fattura' => 'date',
        'data_scadenza' => 'date',
        'saldo' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Giorni di ritardo rispetto alla scadenza; null se non scaduta o senza
     * data di scadenza. Sempre calcolato su "oggi", non memorizzato: una
     * colonna diventerebbe sbagliata il giorno dopo l'import.
     */
    public function giorniDiRitardo(): ?int
    {
        if ($this->data_scadenza === null || $this->data_scadenza->isFuture()) {
            return null;
        }

        return (int) $this->data_scadenza->diffInDays(now());
    }

    public function scaduta(): bool
    {
        return $this->giorniDiRitardo() !== null;
    }
}
