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
     * Chi puo' mandare un rapportino a Eureka.
     *
     * Non e' un salvataggio: crea sul gestionale un documento che non si puo'
     * piu' cancellare, nemmeno in ambiente di test, e da quel momento il
     * rapportino qui e' bloccato (isSuEureka()). Il tecnico lo compila, a
     * mandarlo ci pensa chi fattura — indicazione dell'ufficio, 04/09/2026.
     *
     * Il controllo vive qui e non solo nel pulsante: e' la stessa ragione per
     * cui viewPrices() e' una policy e non un ->visible().
     */
    public function sendToGestionale(User $user, ServiceReport $serviceReport): bool
    {
        return $this->view($user, $serviceReport)
            && $user->can('send_to_gestionale_service::report');
    }

    /** Copie allegabili all'email, in ordine di quanto mostrano. */
    public const COPIA_SENZA_ARTICOLI = 'senza_articoli';

    public const COPIA_CON_ARTICOLI = 'con_articoli';

    public const COPIA_CON_PREZZI = 'con_prezzi';

    /**
     * Chi puo' mandare il rapportino al cliente via email.
     *
     * Tutti, tecnici compresi: quello che cambia col ruolo non e' se si
     * manda, ma COSA si manda e A CHI — vedi copieConsentite() e
     * puoScrivereAlPagante() qui sotto.
     */
    public function sendEmail(User $user, ServiceReport $serviceReport): bool
    {
        return $this->view($user, $serviceReport)
            && $user->can('send_email_service::report');
    }

    /**
     * Quali copie del rapportino questa persona puo' allegare.
     *
     * Il tecnico ne ha una sola, e senza scelta: quella senza articoli. Fa
     * firmare al cliente di aver ricevuto l'intervento, non l'elenco dei
     * ricambi montati, che e' materia di chi fattura (indicazione
     * dell'ufficio, 04/09/2026).
     *
     * @return array<int, string>
     */
    public function copieEmailConsentite(User $user, ServiceReport $serviceReport): array
    {
        if (! $user->can('send_email_completo_service::report')) {
            return [self::COPIA_SENZA_ARTICOLI];
        }

        return [self::COPIA_SENZA_ARTICOLI, self::COPIA_CON_ARTICOLI, self::COPIA_CON_PREZZI];
    }

    /**
     * Se puo' spedire anche all'indirizzo di chi paga, quando a pagare e' un
     * altro (Dersut, Martellozzo...).
     *
     * Il tecnico no: scrive al luogo dove ha lavorato, e basta. Col pagante
     * ci parla l'ufficio, che sa cosa e' gia' stato concordato.
     */
    public function puoScrivereAlPagante(User $user, ServiceReport $serviceReport): bool
    {
        return $user->can('send_email_completo_service::report');
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
