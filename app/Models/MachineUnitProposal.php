<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proposta di una MachineUnit nuova trovata su Eureka
 * (/show/q/art_installati) ma non ancora presente nel CRM — vedi la
 * migration per il perche' di una tabella a parte invece di un campo su
 * MachineUnit.
 */
class MachineUnitProposal extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'product_id',
        'serial_number',
        'model_name',
        'eureka_article_id',
        'eureka_article_code',
        'dismissed_at',
    ];

    protected $casts = [
        'dismissed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
