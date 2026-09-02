<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ServiceReport;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServiceReportPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_service::report');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ServiceReport $serviceReport): bool
    {
        // Ticket sicurezza 1.1: la route service-reports.pdf vive fuori dal
        // pannello Filament (Filament::getTenant() torna null li'), quindi lo
        // scope automatico tenant di BelongsToTenant non si applica: senza il
        // confronto esplicito col tenant del rapportino, il solo permesso di
        // ruolo bastava a scaricare il rapportino di un ALTRO tenant.
        // is_super_admin bypassa comunque tutto via Gate::before.
        return $user->can('view_service::report') && $user->tenant_id === $serviceReport->tenant_id;
    }

    /**
     * Chi puo' vedere i prezzi su un rapportino.
     *
     * I dipendenti non devono MAI far uscire un rapportino con i prezzi
     * (indicazione dell'ufficio, 02/09/2026): il costo dell'intervento non e'
     * roba che il tecnico discute in cantiere. L'amministrazione invece
     * sceglie di volta in volta se stampare la copia con o senza.
     *
     * Il controllo vive qui e non solo nel bottone: la route
     * service-reports.pdf sta fuori dal pannello e accetta ?prezzi=1 da
     * chiunque sappia digitarlo. Nascondere la voce di menu non e' una
     * protezione.
     */
    public function viewPrices(User $user, ServiceReport $serviceReport): bool
    {
        return $this->view($user, $serviceReport)
            && $user->can('view_prices_service::report');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_service::report');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ServiceReport $serviceReport): bool
    {
        // Rapportini arrivati da Eureka o gia' inviati la' non sono piu'
        // modificabili da CRM, a prescindere dal ruolo — vedi
        // ServiceReport::isLocked(). "Completato" da solo non blocca.
        return $user->can('update_service::report') && ! $serviceReport->isLocked();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ServiceReport $serviceReport): bool
    {
        return $user->can('delete_service::report');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_service::report');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, ServiceReport $serviceReport): bool
    {
        return $user->can('force_delete_service::report');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_service::report');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, ServiceReport $serviceReport): bool
    {
        return $user->can('restore_service::report');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_service::report');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, ServiceReport $serviceReport): bool
    {
        return $user->can('replicate_service::report');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_service::report');
    }
}
