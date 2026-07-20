<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOptionSlotItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'slot_id',
        'component_product_id',
        'price_delta_override',
        'sort_order',
    ];

    protected $casts = [
        'price_delta_override' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ProductOptionSlot::class, 'slot_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
