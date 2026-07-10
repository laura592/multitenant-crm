<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant, HasUuids, SharedAcrossTenants;

    public const TYPE_MACHINE = 'machine';
    public const TYPE_AUXILIARY_UNIT = 'auxiliary_unit';
    public const TYPE_OPTION = 'option';
    public const TYPE_ACCESSORY = 'accessory';
    public const TYPE_SERVICE = 'service';

    public const SOURCE_FRANKE = 'franke_ufficiale';
    public const SOURCE_THIRD_PARTY = 'terzo';

    protected $fillable = [
        'tenant_id',
        'category_id',
        'product_family_id',
        'sku',
        'type',
        'name',
        'description',
        'image',
        'source',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Opzioni/unità ausiliarie compatibili quando questo prodotto è la variante base scelta.
     */
    public function compatibleOptions(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_compatibilities', 'base_product_id', 'option_product_id')
            ->withPivot('option_group_id', 'constraint_type', 'sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * Varianti base con cui questo prodotto (opzione/unità ausiliaria) è compatibile.
     */
    public function compatibleBases(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_compatibilities', 'option_product_id', 'base_product_id')
            ->withPivot('option_group_id', 'constraint_type', 'sort_order');
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(ProductCompatibility::class, 'base_product_id');
    }

    /**
     * Altri prodotti richiesti se questo è selezionato (es. DualMilk -> Self-Serve Package).
     */
    public function requiredProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_requirements', 'product_id', 'requires_product_id');
    }

    /**
     * Prodotti incompatibili con questo, se selezionato.
     */
    public function excludedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_exclusions', 'product_id', 'excludes_product_id');
    }

    public function getCurrentPrice(): ?ProductPrice
    {
        $today = now()->toDateString();

        $validPrice = $this->prices()
            ->where(function ($query) use ($today) {
                $query->where('valid_from', '<=', $today)->orWhereNull('valid_from');
            })
            ->where(function ($query) use ($today) {
                $query->where('valid_to', '>=', $today)->orWhereNull('valid_to');
            })
            ->orderByDesc('created_at')
            ->first();

        return $validPrice ?? $this->prices()->orderByDesc('created_at')->first();
    }

    public function isMachine(): bool
    {
        return $this->type === self::TYPE_MACHINE;
    }
}
