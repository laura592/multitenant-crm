<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCompatibility extends Model
{
    use HasUuids;

    public const CONSTRAINT_COMPATIBLE = 'compatible';
    public const CONSTRAINT_REQUIRED = 'required';

    protected $table = 'product_compatibilities';

    protected $fillable = [
        'base_product_id',
        'option_product_id',
        'option_group_id',
        'constraint_type',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function baseProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'base_product_id');
    }

    public function optionProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'option_product_id');
    }

    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'option_group_id');
    }

    public function isRequired(): bool
    {
        return $this->constraint_type === self::CONSTRAINT_REQUIRED;
    }
}
