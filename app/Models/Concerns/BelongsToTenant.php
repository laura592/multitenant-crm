<?php

namespace App\Models\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (! $model->tenant_id && $tenant = Filament::getTenant()) {
                $model->tenant_id = $tenant->id;
            }
        });

        static::addGlobalScope('tenant', function (Builder $query) {
            if (auth()->user()?->is_super_admin) {
                return;
            }

            $tenantId = Filament::getTenant()?->id;

            if (! $tenantId) {
                return;
            }

            $table = $query->getModel()->getTable();

            $query->where(function (Builder $q) use ($table, $tenantId) {
                $q->where("{$table}.tenant_id", $tenantId);

                if (in_array(SharedAcrossTenants::class, class_uses_recursive($q->getModel()))) {
                    $q->orWhereNull("{$table}.tenant_id");
                }
            });
        });
    }
}
