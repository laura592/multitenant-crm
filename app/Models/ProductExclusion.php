<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductExclusion extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'excludes_product_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function excludesProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'excludes_product_id');
    }
}
