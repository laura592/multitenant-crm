<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Il dipendente vede/corregge solo i propri record; un responsabile
 * (is_super_admin, o ruolo "partner_owner" assegnato via Shield nel tenant
 * corrente) vede tutto il tenant (docs/architecture.md §12.2).
 */
trait ScopesToOwnUserUnlessResponsabile
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user || static::isResponsabile($user)) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public static function isResponsabile($user): bool
    {
        return $user->is_super_admin || $user->hasRole('partner_owner');
    }
}
