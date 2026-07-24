<?php

namespace App\Policies;

use App\Models\User;
use App\Models\LeaveRequest;
use Illuminate\Auth\Access\HandlesAuthorization;

class LeaveRequestPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_leave::request');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->can('view_leave::request');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_leave::request');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->can('update_leave::request');
    }

    /**
     * Approvare/rifiutare una richiesta, anche per cambiare idea su una gia'
     * decisa: riservato a chi e' "responsabile" (is_super_admin bypassa
     * comunque tutto via Gate::before). "amministrazione" ha update_leave::
     * request per integrare i dati ma NON l'autorita' di approvazione (vedi
     * App\Support\RolePermissions).
     */
    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Una richiesta ancora "richiesto" segue i permessi normali di update;
     * una gia' decisa (approvato/rifiutato) puo' essere corretta/cancellata
     * solo da un responsabile, per non lasciare che un dipendente alteri
     * l'esito di una decisione gia' presa (vedi LeaveRequestResource, azioni
     * Modifica/Elimina della tabella).
     */
    public function updateAfterDecision(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($leaveRequest->status === 'richiesto') {
            return $user->can('update_leave::request');
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->can('delete_leave::request');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_leave::request');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->can('force_delete_leave::request');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_leave::request');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->can('restore_leave::request');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_leave::request');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->can('replicate_leave::request');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_leave::request');
    }
}
