<?php

namespace Tests\Feature;

use App\Filament\Pages\PagantiMacchine;
use App\Filament\Resources\RoleResource;
use App\Support\RolePermissions;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La schermata dei privilegi di un ruolo (shield/roles/{id}/edit).
 *
 * Nasce da una segnalazione dell'ufficio del 04/09/2026: "Chi paga per chi"
 * non compariva fra i privilegi, e l'elenco era diventato illeggibile.
 */
class SchermataPrivilegiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_chi_paga_per_chi_compare_fra_i_privilegi(): void
    {
        $this->assertArrayHasKey('page_PagantiMacchine', RoleResource::getPageOptions());
    }

    /**
     * La pagina di dettaglio non e' un privilegio a se': una sola casella
     * governa l'elenco e le sue schede.
     */
    public function test_il_dettaglio_pagante_non_e_un_privilegio_separato(): void
    {
        $this->assertArrayNotHasKey('page_DettaglioPagante', RoleResource::getPageOptions());
    }

    /**
     * Chi vedeva la pagina prima che avesse un permesso suo deve continuare a
     * vederla: il cancello era il permesso sulle macchine.
     */
    public function test_la_vede_chi_vede_le_macchine(): void
    {
        foreach (['dipendente', 'amministrazione', 'admin', 'amministratore'] as $ruolo) {
            $permessi = RolePermissions::for($ruolo);

            $this->assertSame(
                in_array('view_any_machine::unit', $permessi, true),
                in_array('page_PagantiMacchine', $permessi, true),
                $ruolo
            );
        }

        $this->assertNotContains('page_PagantiMacchine', RolePermissions::for('partner'));
    }

    public function test_la_pagina_ha_il_suo_permesso(): void
    {
        $this->assertContains(
            'page_PagantiMacchine',
            collect(FilamentShield::getPages())->pluck('permission')->all()
        );
    }

    /** Le caselle sono in italiano: la traduzione del pacchetto le lascia in inglese. */
    public function test_le_etichette_sono_in_italiano(): void
    {
        $etichette = RoleResource::getResourcePermissionOptions([
            'fqcn' => \App\Filament\Resources\CustomerResource::class,
            'resource' => 'customer',
            'model' => 'Customer',
        ]);

        $this->assertSame('Vedere l\'elenco', $etichette['view_any_customer']);
        $this->assertSame('Cestinare', $etichette['delete_customer']);
        $this->assertSame('Eliminare per sempre', $etichette['force_delete_customer']);

        foreach ($etichette as $etichetta) {
            $this->assertDoesNotMatchRegularExpression('/^(View|Delete|Force|Restore)/', $etichetta);
        }
    }

    /**
     * "Ripristina" ed "elimina definitivamente" hanno senso solo dove c'e' il
     * cestino. Altrove erano caselle che non accendevano niente.
     */
    public function test_il_cestino_compare_solo_dove_esiste(): void
    {
        $conCestino = RoleResource::getResourcePermissionOptions([
            'fqcn' => \App\Filament\Resources\CustomerResource::class,
            'resource' => 'customer',
            'model' => 'Customer',
        ]);

        $this->assertArrayHasKey('restore_customer', $conCestino);
        $this->assertArrayHasKey('force_delete_customer', $conCestino);

        $senzaCestino = RoleResource::getResourcePermissionOptions([
            'fqcn' => \App\Filament\Resources\BrandResource::class,
            'resource' => 'brand',
            'model' => 'Brand',
        ]);

        $this->assertArrayHasKey('delete_brand', $senzaCestino);
        $this->assertArrayNotHasKey('restore_brand', $senzaCestino);
        $this->assertArrayNotHasKey('restore_any_brand', $senzaCestino);
        $this->assertArrayNotHasKey('force_delete_brand', $senzaCestino);
    }

    /** Nessun ruolo deve piu' portarsi dietro un restore che non fa niente. */
    public function test_i_ruoli_non_hanno_ripristini_inutili(): void
    {
        foreach (['dipendente', 'amministrazione', 'partner', 'admin', 'amministratore'] as $ruolo) {
            foreach (RolePermissions::for($ruolo) as $permesso) {
                if (! preg_match('/^(restore_any|restore|force_delete_any|force_delete)_(.+)$/', $permesso, $pezzi)) {
                    continue;
                }

                $this->assertContains($pezzi[2], [
                    'customer', 'machine::unit', 'quote', 'quote::group', 'service::report', 'tenant',
                ], "{$ruolo}: {$permesso}");
            }
        }
    }

    /** Le due liste del cestino devono restare in fase, o il sync va in loop. */
    public function test_le_due_liste_del_cestino_coincidono(): void
    {
        $daSchermata = (new \ReflectionClass(RoleResource::class))->getConstant('COL_CESTINO');
        $daRuoli = (new \ReflectionClass(RolePermissions::class))->getConstant('COL_CESTINO');

        $this->assertSame($daSchermata, $daRuoli);
    }

    /**
     * Ogni risorsa deve finire in un'area della sidebar. "Altro" e' la rete di
     * sicurezza perche' nessuna sparisca, ma se ci finisce qualcosa vuol dire
     * che manca un NavigationGroup.
     */
    public function test_ogni_risorsa_ha_la_sua_area(): void
    {
        $orfane = collect(FilamentShield::getResources())
            ->filter(fn (array $entity) => ! in_array(
                (string) $entity['fqcn']::getNavigationGroup(),
                (new \ReflectionClass(RoleResource::class))->getConstant('AREE'),
                true
            ))
            ->map(fn (array $entity) => $entity['fqcn'])
            ->values()
            ->all();

        $this->assertSame([], $orfane, 'Risorse senza area: '.implode(', ', $orfane));
    }

    public function test_le_schede_delle_aree_coprono_tutte_le_risorse(): void
    {
        $schede = (new \ReflectionMethod(RoleResource::class, 'schedeDelleAree'))->invoke(null);

        $this->assertNotEmpty($schede);

        $totale = collect($schede)->sum(fn ($scheda) => $scheda->getBadge());

        $this->assertSame(count(FilamentShield::getResources()), $totale);
    }

    /**
     * Le contabili erano fuori dalla matrice: si passava o no per
     * is_super_admin, e nella schermata dei privilegi non c'era niente.
     */
    public function test_le_pagine_contabili_sono_nella_matrice(): void
    {
        $pagine = RoleResource::getPageOptions();

        foreach (['page_ScadutoClienti', 'page_AnalisiContabili', 'page_CashFlow'] as $pagina) {
            $this->assertArrayHasKey($pagina, $pagine);
        }

        // Il dettaglio dello scaduto no: segue l'elenco.
        $this->assertArrayNotHasKey('page_DettaglioScaduto', $pagine);
    }

    /** L'ufficio le ha volute su "admin" e su nessun altro ruolo (04/09/2026). */
    public function test_le_contabili_sono_solo_di_admin(): void
    {
        $contabili = ['page_ScadutoClienti', 'page_AnalisiContabili', 'page_CashFlow'];

        foreach ($contabili as $pagina) {
            $this->assertContains($pagina, RolePermissions::for('admin'), $pagina);
        }

        foreach (['dipendente', 'amministrazione', 'partner', 'amministratore'] as $ruolo) {
            foreach ($contabili as $pagina) {
                $this->assertNotContains($pagina, RolePermissions::for($ruolo), "{$ruolo} / {$pagina}");
            }
        }
    }

    /**
     * Il cancello e' cambiato di natura, non di severita': chi e' staff
     * master continua a entrare senza che nessuno gli conceda niente.
     */
    public function test_lo_staff_master_vede_le_contabili_senza_permessi(): void
    {
        $tenant = \App\Models\Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $staff = \App\Models\User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Staff Alex',
            'email' => 'staff@test.it',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($staff);

        $this->assertTrue(\App\Filament\Pages\CashFlow::canAccess());
        $this->assertTrue(\App\Filament\Pages\ScadutoClienti::canAccess());
        $this->assertTrue(\App\Filament\Pages\AnalisiContabili::canAccess());
        $this->assertTrue(\App\Filament\Pages\DettaglioScaduto::canAccess());

        $chiunque = \App\Models\User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Senza permesso',
            'email' => 'senza@test.it',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($chiunque);

        $this->assertFalse(\App\Filament\Pages\CashFlow::canAccess());
        $this->assertFalse(\App\Filament\Pages\DettaglioScaduto::canAccess());
    }

    public function test_il_dettaglio_scaduto_segue_lelenco(): void
    {
        $this->assertSame(
            (new \ReflectionMethod(\App\Filament\Pages\ScadutoClienti::class, 'canAccess'))->getName(),
            (new \ReflectionMethod(\App\Filament\Pages\DettaglioScaduto::class, 'canAccess'))->getName()
        );

        $this->assertStringContainsString(
            'ScadutoClienti::canAccess()',
            file_get_contents(app_path('Filament/Pages/DettaglioScaduto.php'))
        );
    }

    public function test_il_dettaglio_pagante_segue_lelenco(): void
    {
        $tenant = \App\Models\Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $senza = \App\Models\User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Senza permesso',
            'email' => 'senza@test.it',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($senza);

        $this->assertFalse(PagantiMacchine::canAccess());
        $this->assertFalse(\App\Filament\Pages\DettaglioPagante::canAccess());

        $con = \App\Models\User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Staff Alex',
            'email' => 'staff@test.it',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($con);

        $this->assertTrue(PagantiMacchine::canAccess());
        $this->assertTrue(\App\Filament\Pages\DettaglioPagante::canAccess());
    }
}
