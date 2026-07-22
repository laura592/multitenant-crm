<?php

namespace App\Support;

/**
 * Unica fonte dei permessi per i 5 ruoli applicativi (dipendente,
 * amministrazione, collaboratore, partner, admin). Usata sia dal seeder di
 * produzione che dai test, cosi i due non rischiano di divergere
 * silenziosamente. Gestione Tenant e Ruoli resta riservata allo staff master
 * (is_super_admin), nessuno dei 5 ruoli le include (docs/architecture.md
 * §5.3).
 *
 * "amministrazione" (es. Cristina) e' un profilo ufficio/HR: vede e corregge
 * ore/ferie di tutti i dipendenti (deve compilarle per il commercialista) ma
 * non ha l'autorita' di approvazione ferie (resta solo a "admin"/staff
 * master) e non crea rapportini, solo li integra. Vedi
 * App\Filament\Concerns\ScopesToOwnUserUnlessResponsabile, che allarga la
 * visibilita' di time_entry/leave_request a questo ruolo senza equipararlo a
 * "responsabile" per le azioni di approvazione.
 */
class RolePermissions
{
    private const VIEW = ['view_any', 'view'];

    private const MANAGE = ['view_any', 'view', 'create', 'update', 'delete', 'delete_any'];

    public static function for(string $role): array
    {
        return match ($role) {
            'collaboratore' => [
                ...self::expand('information::request', self::MANAGE),
                'create_customer',
            ],
            'partner' => [
                ...self::expand('brand', self::VIEW),
                ...self::expand('category', self::VIEW),
                ...self::expand('product', self::VIEW),
                ...self::expand('product::family', self::VIEW),
                ...self::expand('customer', ['view_any', 'view', 'create', 'update']),
                ...self::expand('quote', ['view_any', 'view', 'create', 'update']),
                'widget_DashboardStatsWidget',
                'widget_LatestQuotesWidget',
            ],
            'dipendente' => [
                ...self::expand('brand', self::VIEW),
                ...self::expand('category', self::VIEW),
                ...self::expand('product', self::VIEW),
                ...self::expand('product::family', self::VIEW),
                // Puo' censire un nuovo cliente incontrato sul campo, ma non
                // correggere/cancellare quelli esistenti (solo admin).
                ...self::expand('customer', ['view_any', 'view', 'create']),
                ...self::expand('quote', self::MANAGE),
                ...self::expand('information::request', self::MANAGE),
                ...self::expand('service::report', self::MANAGE),
                ...self::expand('maintenance::schedule', self::MANAGE),
                ...self::expand('deadline', self::VIEW),
                ...self::expand('vehicle', self::VIEW),
                ...self::expand('machine::unit', self::MANAGE),
                ...self::expand('material', self::VIEW),
                ...self::expand('material::order', self::MANAGE),
                ...self::expand('supplier', self::VIEW),
                // Vede solo le proprie ore/ferie (ScopesToOwnUserUnlessResponsabile).
                ...self::expand('time::entry', ['view_any', 'view', 'create', 'update']),
                ...self::expand('leave::request', ['view_any', 'view', 'create', 'update']),
                'widget_TimbraWidget',
                'page_RiepilogoOre',
                'page_ClientiVicini',
            ],
            'amministrazione' => [
                // Profilo ufficio: nessun accesso a catalogo/preventivi/interventi
                // sul campo, solo cio' che serve per la gestione amministrativa
                // del personale e l'integrazione dei rapportini.
                ...self::expand('customer', self::VIEW),
                // Non crea rapportini (li fanno i tecnici), ma li puo' correggere.
                ...self::expand('service::report', ['view_any', 'view', 'update']),
                // Vede/corregge ore e ferie di tutto il personale per passarle al
                // commercialista, ma niente azione di approvazione (resta a chi e'
                // "responsabile": admin/staff master).
                ...self::expand('time::entry', ['view_any', 'view', 'create', 'update']),
                ...self::expand('leave::request', ['view_any', 'view', 'create', 'update']),
                'widget_TimbraWidget',
                'page_RiepilogoOre',
            ],
            'admin' => [
                ...self::expand('brand', self::MANAGE),
                ...self::expand('category', self::MANAGE),
                ...self::expand('product', self::MANAGE),
                ...self::expand('product::family', self::MANAGE),
                ...self::expand('customer', self::MANAGE),
                ...self::expand('quote', self::MANAGE),
                ...self::expand('information::request', self::MANAGE),
                ...self::expand('service::report', self::MANAGE),
                ...self::expand('maintenance::schedule', self::MANAGE),
                ...self::expand('deadline', self::MANAGE),
                ...self::expand('vehicle', self::MANAGE),
                ...self::expand('material', self::MANAGE),
                ...self::expand('material::order', self::MANAGE),
                ...self::expand('supplier', self::MANAGE),
                ...self::expand('time::entry', self::MANAGE),
                ...self::expand('leave::request', self::MANAGE),
                ...self::expand('payment::method', self::MANAGE),
                ...self::expand('machine::unit', self::MANAGE),
                // Unico ruolo (oltre allo staff master is_super_admin) che puo'
                // creare/gestire utenti.
                ...self::expand('user', self::MANAGE),
                'widget_TimbraWidget',
                'widget_DashboardStatsWidget',
                'widget_LatestQuotesWidget',
                'widget_UpcomingDeadlinesWidget',
                'page_RiepilogoOre',
                'page_ClientiVicini',
            ],
            default => throw new \InvalidArgumentException("Ruolo sconosciuto: {$role}"),
        };
    }

    public static function roles(): array
    {
        return ['dipendente', 'amministrazione', 'collaboratore', 'partner', 'admin'];
    }

    private static function expand(string $resource, array $prefixes): array
    {
        return array_map(fn (string $prefix) => "{$prefix}_{$resource}", $prefixes);
    }
}
