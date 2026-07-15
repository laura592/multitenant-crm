<?php

namespace App\Support;

/**
 * Unica fonte dei permessi per i 4 ruoli applicativi (dipendente, collaboratore,
 * partner, admin). Usata sia dal seeder di produzione che dai test, cosi i due
 * non rischiano di divergere silenziosamente. Gestione Tenant e Ruoli resta
 * riservata allo staff master (is_super_admin), nessuno dei 4 ruoli le include
 * (docs/architecture.md §5.3).
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
                ...self::expand('category', self::VIEW),
                ...self::expand('product', self::VIEW),
                ...self::expand('product::family', self::VIEW),
                ...self::expand('product::option::group', self::VIEW),
                ...self::expand('customer', ['view_any', 'view', 'create', 'update']),
                ...self::expand('quote', ['view_any', 'view', 'create', 'update']),
                'widget_DashboardStatsWidget',
                'widget_LatestQuotesWidget',
            ],
            'dipendente' => [
                ...self::expand('category', self::VIEW),
                ...self::expand('product', self::VIEW),
                ...self::expand('product::family', self::VIEW),
                ...self::expand('product::option::group', self::VIEW),
                ...self::expand('customer', self::MANAGE),
                ...self::expand('quote', self::MANAGE),
                ...self::expand('information::request', self::MANAGE),
                ...self::expand('service::report', self::MANAGE),
                ...self::expand('maintenance::schedule', self::MANAGE),
                ...self::expand('deadline', self::VIEW),
                ...self::expand('vehicle', self::VIEW),
                ...self::expand('time::entry', ['view_any', 'view', 'create', 'update']),
                ...self::expand('leave::request', ['view_any', 'view', 'create', 'update']),
                'widget_TimbraWidget',
                'page_RiepilogoOre',
            ],
            'admin' => [
                ...self::expand('category', self::MANAGE),
                ...self::expand('product', self::MANAGE),
                ...self::expand('product::family', self::MANAGE),
                ...self::expand('product::option::group', self::MANAGE),
                ...self::expand('customer', self::MANAGE),
                ...self::expand('quote', self::MANAGE),
                ...self::expand('information::request', self::MANAGE),
                ...self::expand('service::report', self::MANAGE),
                ...self::expand('maintenance::schedule', self::MANAGE),
                ...self::expand('deadline', self::MANAGE),
                ...self::expand('vehicle', self::MANAGE),
                ...self::expand('time::entry', self::MANAGE),
                ...self::expand('leave::request', self::MANAGE),
                ...self::expand('payment::method', self::MANAGE),
                'widget_TimbraWidget',
                'widget_DashboardStatsWidget',
                'widget_LatestQuotesWidget',
                'widget_UpcomingDeadlinesWidget',
                'page_RiepilogoOre',
            ],
            default => throw new \InvalidArgumentException("Ruolo sconosciuto: {$role}"),
        };
    }

    public static function roles(): array
    {
        return ['dipendente', 'collaboratore', 'partner', 'admin'];
    }

    private static function expand(string $resource, array $prefixes): array
    {
        return array_map(fn (string $prefix) => "{$prefix}_{$resource}", $prefixes);
    }
}
