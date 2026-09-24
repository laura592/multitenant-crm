<?php

namespace App\Policies;

use App\Models\InterventoProgrammato;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Il giro programmato lo prepara l'ufficio, lo leggono i tecnici.
 *
 * Il taglio vero non e' qui ma nella query (vedi
 * InterventoProgrammatoResource::getEloquentQuery(), che a un dipendente
 * mostra solo il proprio giro): qui si decide chi puo' fare cosa, la' chi
 * vede cosa.
 *
 * Attenzione: `php artisan shield:generate` riscrive questo file da un
 * modello e si porta via assegna(), da cui dipende la separazione fra chi
 * prepara e chi esegue. Se succede, va rimesso.
 */
class InterventoProgrammatoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_intervento::programmato');
    }

    public function view(User $user, InterventoProgrammato $interventoProgrammato): bool
    {
        return $user->can('view_intervento::programmato');
    }

    public function create(User $user): bool
    {
        return $user->can('create_intervento::programmato');
    }

    public function update(User $user, InterventoProgrammato $interventoProgrammato): bool
    {
        return $user->can('update_intervento::programmato');
    }

    public function delete(User $user, InterventoProgrammato $interventoProgrammato): bool
    {
        return $user->can('delete_intervento::programmato');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_intervento::programmato');
    }

    public function forceDelete(User $user, InterventoProgrammato $interventoProgrammato): bool
    {
        return $user->can('force_delete_intervento::programmato');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_intervento::programmato');
    }

    public function restore(User $user, InterventoProgrammato $interventoProgrammato): bool
    {
        return $user->can('restore_intervento::programmato');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_intervento::programmato');
    }

    /**
     * Assegnare il giro e' dell'ufficio: un tecnico spunta quello che ha
     * fatto, non decide chi ci va. Sta sul permesso di creazione perche' chi
     * prepara il programma e' lo stesso che lo distribuisce.
     */
    public function assegna(User $user): bool
    {
        return $user->can('create_intervento::programmato');
    }
}
