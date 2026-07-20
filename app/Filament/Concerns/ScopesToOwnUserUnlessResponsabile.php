<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Il dipendente vede/corregge solo i propri record; un responsabile
 * (is_super_admin, o ruolo "admin" assegnato via Shield nel tenant
 * corrente) vede tutto il tenant (docs/architecture.md §12.2). Il ruolo
 * "amministrazione" (es. Cristina) vede anch'esso tutti i record — deve
 * compilare ore/ferie per il commercialista — ma NON conta come
 * "responsabile": resta escluso dalle azioni di approvazione ferie e dal
 * poter riassegnare un record a un altro dipendente (vedi isResponsabile()
 * usato separatamente per quei casi).
 */
trait ScopesToOwnUserUnlessResponsabile
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user || static::isResponsabile($user) || $user->hasRole('amministrazione')) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public static function isResponsabile($user): bool
    {
        return $user->is_super_admin || $user->hasRole('admin');
    }
}
