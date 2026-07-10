<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequirement extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'requires_product_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requiresProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'requires_product_id');
    }
}
