<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sostituito da ProductOptionSlot (files/DATABASE-SCHEMA.md): la tabella
 * viene droppata da 2026_07_16_010400_drop_product_compatibilities_and_option_groups_tables.
 * Il model resta solo perche' quella migrazione lo usa per la conversione
 * dati una tantum (App\Console\Commands\MigrateCompatibilitiesToSlots) -
 * nessun altro codice applicativo deve farvi riferimento.
 */
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
