<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteProduct extends Model
{
    use HasUuids;

    protected $fillable = [
        'quote_id',
        'product_id',
        'parent_quote_product_id',
        'quantity',
        'price',
        'discount',
        'tax',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'discount' => 'integer',
        'tax' => 'integer',
        'total' => 'decimal:2',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(QuoteProduct::class, 'parent_quote_product_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuoteProduct::class, 'parent_quote_product_id');
    }

    public function isBase(): bool
    {
        return is_null($this->parent_quote_product_id);
    }

    public function isOption(): bool
    {
        return ! is_null($this->parent_quote_product_id);
    }

    public function getTotalWithOptions(): float
    {
        return (float) $this->total + (float) $this->options->sum('total');
    }
}
