<?php

namespace App\Policies;

use App\Models\Agente;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * L'anagrafica agenti: la tiene chi gestisce le vendite.
 *
 * Tecnici e ufficio la leggono — sapere chi segue un cliente serve a
 * entrambi — ma l'elenco lo cura chi amministra: un agente in piu' scritto
 * a caso rompe il raggruppamento, che e' tutto il motivo per cui esiste.
 */
class AgentePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_agente');
    }

    public function view(User $user, Agente $agente): bool
    {
        return $user->can('view_agente');
    }

    public function create(User $user): bool
    {
        return $user->can('create_agente');
    }

    public function update(User $user, Agente $agente): bool
    {
        return $user->can('update_agente');
    }

    public function delete(User $user, Agente $agente): bool
    {
        return $user->can('delete_agente');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_agente');
    }

    public function forceDelete(User $user, Agente $agente): bool
    {
        return $user->can('force_delete_agente');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_agente');
    }

    public function restore(User $user, Agente $agente): bool
    {
        return $user->can('restore_agente');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_agente');
    }
}
