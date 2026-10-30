<?php

namespace App\Policies;

use App\Models\LetturaCasetta;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * La lettura la fa il tecnico sul posto, il rendiconto lo manda l'ufficio.
 *
 * Al tecnico serve creare e correggere (un numero battuto male sul telefono
 * si sistema subito, non si chiede a un admin); cancellare no, come per ogni
 * altra cosa che arriva dal campo.
 */
class LetturaCasettaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_lettura::casetta');
    }

    public function view(User $user, LetturaCasetta $letturaCasetta): bool
    {
        return $user->can('view_lettura::casetta');
    }

    public function create(User $user): bool
    {
        return $user->can('create_lettura::casetta');
    }

    public function update(User $user, LetturaCasetta $letturaCasetta): bool
    {
        return $user->can('update_lettura::casetta');
    }

    public function delete(User $user, LetturaCasetta $letturaCasetta): bool
    {
        return $user->can('delete_lettura::casetta');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_lettura::casetta');
    }

    public function forceDelete(User $user, LetturaCasetta $letturaCasetta): bool
    {
        return $user->can('force_delete_lettura::casetta');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_lettura::casetta');
    }

    public function restore(User $user, LetturaCasetta $letturaCasetta): bool
    {
        return $user->can('restore_lettura::casetta');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_lettura::casetta');
    }
}
