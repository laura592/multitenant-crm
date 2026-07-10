<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductOptionGroup extends Model
{
    use BelongsToTenant, HasUuids, SharedAcrossTenants;

    public const SELECTION_SINGLE = 'single';
    public const SELECTION_MULTIPLE = 'multiple';

    protected $fillable = [
        'tenant_id',
        'name',
        'label',
        'selection_type',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function isSingleChoice(): bool
    {
        return $this->selection_type === self::SELECTION_SINGLE;
    }
}
