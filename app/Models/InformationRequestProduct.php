<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * La pivot richiesta-informazioni ↔ prodotto ha una chiave `id` uuid come
 * tutte le tabelle di questo progetto, ma nessuno la valorizzava: senza
 * ->using(), BelongsToMany::sync() scrive direttamente con il query builder,
 * saltando i model (e quindi HasUuids). In produzione (MySQL strict) salvare
 * una richiesta informazioni con dei prodotti moriva con "Field 'id' doesn't
 * have a default value" — vedi il log del 2026-08-28.
 */
class InformationRequestProduct extends Pivot
{
    use HasUuids;

    protected $table = 'information_request_product';

    public $incrementing = false;
}
