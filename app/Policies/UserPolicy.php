<?php

namespace App\Policies;

use App\Models\User;

use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_user');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->can('view_user') && $this->nelPerimetroDi($user, $model);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->can('create_user');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->can('update_user') && $this->nelPerimetroDi($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->can('delete_user') && $this->nelPerimetroDi($user, $model);
    }

    /**
     * Determine whether the user can bulk delete.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_user');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $user->can('force_delete_user') && $this->nelPerimetroDi($user, $model);
    }

    /**
     * Determine whether the user can permanently bulk delete.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_user');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->can('restore_user') && $this->nelPerimetroDi($user, $model);
    }

    /**
     * Determine whether the user can bulk restore.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_user');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, User $model): bool
    {
        return $user->can('replicate_user') && $this->nelPerimetroDi($user, $model);
    }

    /**
     * Determine whether the user can reorder.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_user');
    }

    /**
     * Il permesso dice COSA si puo' fare, questo dice SU CHI.
     *
     * Senza, bastava il permesso: il ruolo "admin" esiste in ogni tenant
     * (App\Support\RolePermissions) e UserResource::getEloquentQuery() mostra
     * di proposito anche gli utenti con tenant_id NULL, cioe' lo staff master
     * Alex, dentro l'elenco Utenti di OGNI partner. Il form Utenti contiene il
     * campo password: un admin di un tenant partner poteva quindi aprire un
     * account staff master, riscrivergli la password ed entrare come super
     * admin ovunque. Verificato dal vivo, vedi UserResourceTest.
     *
     * Lo staff master non passa mai di qui: Gate::before (AppServiceProvider)
     * lo autorizza prima che la policy venga interrogata. Chi arriva fin qui
     * e' sempre un utente di tenant, e puo' toccare solo chi sta nel suo.
     *
     * tenant_id NULL e' fuori dal perimetro di chiunque, non "uguale a NULL":
     * il NULL su users e' esattamente la marca dello staff master (vedi
     * User::getTenants()), e due NULL non devono mai risultare "stesso tenant".
     */
    private function nelPerimetroDi(User $user, User $model): bool
    {
        return $model->tenant_id !== null
            && $model->tenant_id === $user->tenant_id;
    }
}
