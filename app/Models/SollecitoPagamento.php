<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un sollecito di pagamento partito verso un cliente, con la fotografia di
 * quello che gli abbiamo chiesto quel giorno.
 *
 * Non e' un collegamento alle partite aperte ma una copia congelata: le
 * partite sono un'istantanea che si ricarica due volte al giorno e sparisce
 * quando la fattura viene incassata (vedi la migrazione
 * 2026_11_27_203000).
 *
 * @property array<int, array<string, mixed>> $partite
 * @property array<int, string>|null $allegati
 */
class SollecitoPagamento extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Il lavoro e' in coda: i PDF si scaricano da Eureka, non in una richiesta web. */
    public const STATO_IN_CODA = 'in_coda';

    /**
     * Preso in carico da un worker. Non e' uno stato decorativo: il driver
     * database rimette in coda i job che girano da piu' di 90 secondi, e
     * questo passaggio e' quello che impedisce al secondo worker di mandare
     * lo stesso sollecito una seconda volta (vedi
     * InviaSollecitoPagamentoJob::handle).
     */
    public const STATO_IN_CORSO = 'in_corso';

    public const STATO_INVIATO = 'inviato';

    public const STATO_FALLITO = 'fallito';

    protected $table = 'solleciti_pagamento';

    protected $fillable = [
        'tenant_id',
        'gestionale_code',
        'customer_id',
        'user_id',
        'recipient_email',
        'cc_email',
        'subject',
        'message',
        'totale_sollecitato',
        'partite',
        'allegati',
        'status',
        'error_message',
    ];

    protected $casts = [
        'gestionale_code' => 'integer',
        'totale_sollecitato' => 'decimal:2',
        'partite' => 'array',
        'allegati' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quante fatture sono davvero partite in allegato. */
    public function quanteFatture(): int
    {
        return count($this->allegati ?? []);
    }
}
