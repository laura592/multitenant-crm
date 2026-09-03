<?php

namespace App\Support;

/**
 * Unica fonte dei permessi per i 5 ruoli applicativi (dipendente,
 * amministrazione, partner, admin, amministratore). Usata sia dal seeder di
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
 *
 * "amministratore" (es. Alessandro Signorato) e' un admin pieno ma senza
 * poteri distruttivi: stesso perimetro di risorse di "admin" (vedi sotto),
 * ma senza delete/delete_any/force_delete/force_delete_any su nessuna -
 * puo' vedere/creare/modificare/ripristinare tutto ma mai eliminare
 * definitivamente ne' cestinare. Mantiene pero' l'autorita' di approvazione
 * ferie di "admin" (vedi LeaveRequestPolicy::approve()/updateAfterDecision(),
 * che riconoscono esplicitamente entrambi i ruoli - non e' automatico dai
 * permessi granulari). Nome scelto deliberatamente vicino a
 * "amministrazione" ma sono due ruoli distinti con perimetri molto diversi:
 * non confonderli in fase di assegnazione.
 */
class RolePermissions
{
    private const VIEW = ['view_any', 'view'];

    private const MANAGE = ['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'restore', 'restore_any'];

    // Solo per le risorse con soft delete dove "admin" deve poter anche
    // eliminare definitivamente (customer, quote, quote::group,
    // service::report, machine::unit) - la cancellazione permanente resta
    // riservata a questo ruolo e allo staff master, mai a dipendente/
    // amministrazione/partner.
    //
    // view_prices_service::report non nasce da Shield (non e' un'azione su
    // una Resource) ma da una regola dell'ufficio: i dipendenti non devono
    // MAI far uscire un rapportino con i prezzi, l'amministrazione sceglie
    // se stamparlo con o senza. Il PDF allegato alla mail non ha prezzi per
    // nessuno. Applicato da ServiceReportPolicy::viewPrices().
    //
    // Solo "amministrazione" e "admin" (indicazione dell'ufficio,
    // 02/09/2026): "amministratore" e' il profilo titolare, che vede i
    // numeri dalle pagine contabili e non ha bisogno di stampare listini
    // sui rapportini.
    private const FULL_MANAGE = [...self::MANAGE, 'force_delete', 'force_delete_any'];

    // Come MANAGE ma senza alcun potere di eliminazione.
    //
    // Lo usano "amministratore" (stesso perimetro di "admin" ma senza
    // delete/delete_any, ne' force_delete che in MANAGE non c'e' mai) e, dal
    // 03/09/2026 su indicazione dell'ufficio, anche "dipendente" e
    // "amministrazione": cancellare non e' una cosa che si fa dal campo o
    // dall'ufficio, si chiede a chi amministra. Chi sbaglia un rapportino lo
    // corregge, non lo fa sparire.
    private const MANAGE_NO_DELETE = ['view_any', 'view', 'create', 'update', 'restore', 'restore_any'];

    public static function for(string $role): array
    {
        return match ($role) {
            'partner' => [
                ...self::expand('brand', self::VIEW),
                ...self::expand('category', self::VIEW),
                ...self::expand('product', self::VIEW),
                ...self::expand('product::family', self::VIEW),
                ...self::expand('customer', ['view_any', 'view', 'create', 'update']),
                ...self::expand('quote', ['view_any', 'view', 'create', 'update']),
                ...self::expand('quote::group', ['view_any', 'view', 'create', 'update']),
                ...self::expand('price::list', self::VIEW),
            ],
            'dipendente' => [
                ...self::expand('brand', self::VIEW),
                ...self::expand('category', self::VIEW),
                ...self::expand('product', self::VIEW),
                ...self::expand('product::family', self::VIEW),
                // Puo' censire un nuovo cliente incontrato sul campo, ma non
                // correggere/cancellare quelli esistenti (solo admin).
                ...self::expand('customer', ['view_any', 'view', 'create']),
                // I preventivi non sono di sua competenza: li vedono/gestiscono
                // solo partner e admin/staff master.
                ...self::expand('information::request', self::MANAGE_NO_DELETE),
                ...self::expand('service::report', self::MANAGE_NO_DELETE),
                ...self::expand('maintenance::schedule', self::MANAGE_NO_DELETE),
                ...self::expand('machine::unit', self::MANAGE_NO_DELETE),
                ...self::expand('lavaggio', self::MANAGE_NO_DELETE),
                ...self::expand('material', self::VIEW),
                ...self::expand('material::order', self::MANAGE_NO_DELETE),
                ...self::expand('supplier', self::VIEW),
                ...self::expand('price::list', self::VIEW),
                // Vede solo le proprie ore/ferie (ScopesToOwnUserUnlessResponsabile).
                // Delete incluso: deve poter correggere da solo una
                // timbratura sbagliata (es. da "Recupera turni passati" su
                // un weekend) senza dover ogni volta passare da un admin -
                // resta comunque scoperto solo le proprie, mai quelle di
                // altri colleghi.
                ...self::expand('time::entry', ['view_any', 'view', 'create', 'update']),
                ...self::expand('leave::request', ['view_any', 'view', 'create', 'update']),
                'widget_TimbraWidget',
                'page_RiepilogoOre',
                'page_ClientiVicini',
            ],
            'amministrazione' => [
                // Profilo ufficio: nessun accesso al catalogo ne' agli
                // interventi sul campo, solo cio' che serve per la gestione
                // amministrativa del personale e l'integrazione dei
                // rapportini.
                ...self::expand('customer', self::VIEW),
                // I preventivi li legge e basta (richiesta dell'ufficio,
                // 03/09/2026): servono a rispondere al telefono e a
                // controllare cosa e' stato promesso, non a rifarli — quelli
                // restano di chi li scrive.
                ...self::expand('quote', self::VIEW),
                ...self::expand('quote::group', self::VIEW),
                // Non crea rapportini (li fanno i tecnici), ma li puo' correggere.
                ...self::expand('service::report', ['view_any', 'view', 'update']),
                // Stampa la copia con i prezzi quando serve: vedi la nota su
                // view_prices_service::report piu' sotto.
                'view_prices_service::report',
                // Vede/corregge ore e ferie di tutto il personale per passarle al
                // commercialista, ma niente azione di approvazione (resta a chi e'
                // "responsabile": admin/staff master).
                ...self::expand('time::entry', ['view_any', 'view', 'create', 'update']),
                ...self::expand('leave::request', ['view_any', 'view', 'create', 'update']),
                // Scadenzario e parco veicoli: gestione completa, non riguardano
                // gli interventi sul campo ma l'amministrazione (assicurazioni,
                // revisioni, rinnovi contratto).
                ...self::expand('deadline', self::MANAGE_NO_DELETE),
                ...self::expand('vehicle', self::MANAGE_NO_DELETE),
                'widget_TimbraWidget',
                'page_RiepilogoOre',
            ],
            'admin' => [
                ...self::expand('brand', self::MANAGE),
                ...self::expand('category', self::MANAGE),
                ...self::expand('product', self::MANAGE),
                ...self::expand('product::family', self::MANAGE),
                ...self::expand('customer', self::FULL_MANAGE),
                ...self::expand('quote', self::FULL_MANAGE),
                ...self::expand('quote::group', self::FULL_MANAGE),
                ...self::expand('information::request', self::MANAGE),
                ...self::expand('service::report', self::FULL_MANAGE),
                'view_prices_service::report',
                ...self::expand('maintenance::schedule', self::MANAGE),
                ...self::expand('deadline', self::MANAGE),
                ...self::expand('vehicle', self::MANAGE),
                ...self::expand('material', self::MANAGE),
                ...self::expand('material::order', self::MANAGE),
                ...self::expand('supplier', self::MANAGE),
                ...self::expand('price::list', self::MANAGE),
                ...self::expand('time::entry', self::MANAGE),
                ...self::expand('leave::request', self::MANAGE),
                ...self::expand('payment::method', self::MANAGE),
                ...self::expand('machine::unit', self::FULL_MANAGE),
                ...self::expand('lavaggio', self::MANAGE),
                // Unico ruolo (oltre allo staff master is_super_admin) che puo'
                // creare/gestire utenti.
                ...self::expand('user', self::MANAGE),
                // Audit log (Epic 6): sola consultazione, nessun ruolo lo puo'
                // creare/modificare/cancellare dal pannello (e' generato in
                // automatico da spatie/laravel-activitylog). Riservato ad
                // admin + staff master, come da ticket 6.3.
                ...self::expand('audit::log', self::VIEW),
                'widget_TimbraWidget',
                'widget_CreaPreventivoWidget',
                'page_RiepilogoOre',
                'page_ClientiVicini',
                'page_NotificationSettings',
                'page_GestionaleSyncReview',
                // Scaduto clienti: strumento di chi segue incassi e solleciti.
            ],
            // Specchio di "admin" sopra, risorsa per risorsa: stesso
            // perimetro, solo MANAGE_NO_DELETE al posto di MANAGE/FULL_MANAGE.
            'amministratore' => [
                ...self::expand('brand', self::MANAGE_NO_DELETE),
                ...self::expand('category', self::MANAGE_NO_DELETE),
                ...self::expand('product', self::MANAGE_NO_DELETE),
                ...self::expand('product::family', self::MANAGE_NO_DELETE),
                ...self::expand('customer', self::MANAGE_NO_DELETE),
                ...self::expand('quote', self::MANAGE_NO_DELETE),
                ...self::expand('quote::group', self::MANAGE_NO_DELETE),
                ...self::expand('information::request', self::MANAGE_NO_DELETE),
                ...self::expand('service::report', self::MANAGE_NO_DELETE),
                ...self::expand('maintenance::schedule', self::MANAGE_NO_DELETE),
                ...self::expand('deadline', self::MANAGE_NO_DELETE),
                ...self::expand('vehicle', self::MANAGE_NO_DELETE),
                ...self::expand('material', self::MANAGE_NO_DELETE),
                ...self::expand('material::order', self::MANAGE_NO_DELETE),
                ...self::expand('supplier', self::MANAGE_NO_DELETE),
                ...self::expand('price::list', self::MANAGE_NO_DELETE),
                ...self::expand('time::entry', self::MANAGE_NO_DELETE),
                ...self::expand('leave::request', self::MANAGE_NO_DELETE),
                ...self::expand('payment::method', self::MANAGE_NO_DELETE),
                ...self::expand('machine::unit', self::MANAGE_NO_DELETE),
                ...self::expand('lavaggio', self::MANAGE_NO_DELETE),
                ...self::expand('user', self::MANAGE_NO_DELETE),
                ...self::expand('audit::log', self::VIEW),
                'widget_TimbraWidget',
                'widget_CreaPreventivoWidget',
                'page_RiepilogoOre',
                'page_ClientiVicini',
                'page_NotificationSettings',
                'page_GestionaleSyncReview',
                // Scaduto clienti: strumento di chi segue incassi e solleciti.
            ],
            default => throw new \InvalidArgumentException("Ruolo sconosciuto: {$role}"),
        };
    }

    public static function roles(): array
    {
        return ['dipendente', 'amministrazione', 'partner', 'admin', 'amministratore'];
    }

    private static function expand(string $resource, array $prefixes): array
    {
        return array_map(fn (string $prefix) => "{$prefix}_{$resource}", $prefixes);
    }
}
