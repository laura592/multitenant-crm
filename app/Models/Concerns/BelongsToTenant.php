<?php

namespace App\Models\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            // array_key_exists (non un semplice check di falsy) per distinguere
            // "tenant_id non toccato dal form" da "impostato esplicitamente a
            // null" - il secondo caso serve al catalogo condiviso (§4.2/§11.2),
            // dove un master admin sceglie deliberatamente NULL = condiviso.
            if (! array_key_exists('tenant_id', $model->getAttributes()) && $tenant = Filament::getTenant()) {
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
