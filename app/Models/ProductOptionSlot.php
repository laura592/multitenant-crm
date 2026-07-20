<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionSlot extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'slot_name',
        'label',
        'min_qty',
        'max_qty',
        'required',
        'sort_order',
    ];

    protected $casts = [
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductOptionSlotItem::class, 'slot_id')->orderBy('sort_order');
    }

    public function isSingleChoice(): bool
    {
        return $this->max_qty === 1;
    }
}
