<?php

namespace Tests\Feature;

use App\Filament\Pages\DettaglioScaduto;
use App\Filament\Pages\ScadutoClienti;
use App\Filament\Widgets\Contabilita\ScadutoOverviewWidget;
use App\Models\Customer;
use App\Models\EurekaPartitaAperta;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il widget in testa alle partite aperte e' un StatsOverviewWidget, quindi
 * Filament lo carica LAZY: la pagina iniziale restituisce un segnaposto e il
 * contenuto arriva con una richiesta Livewire separata. Lo smoke test che
 * fa GET sulla pagina non esegue mai quel secondo giro, quindi un errore
 * dentro getStats() non verrebbe intercettato da li'.
 */
class ScadutoWidgetTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_widget_renders_without_errors(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        // Staff master: le pagine contabili non passano piu' dai ruoli,
        // sono riservate a is_super_admin (vedi il loro canAccess()).
        $user->update(['is_super_admin' => true]);

        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 18, 'ragione_sociale' => 'A & A SNC',
            'anno' => 2026, 'numero_fattura' => '43',
            'data_fattura' => '2026-02-28', 'data_scadenza' => '2026-02-28', 'saldo' => 174.92,
        ]);
        // Riga COLLEGATA a un cliente del CRM: e' l'unico caso in cui la
        // colonna "Anagrafica" costruisce davvero l'URL verso la scheda
        // cliente, e quindi l'unico che esercita quel codice.
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Hotel Marco Polo', 'gestionale_code' => 3033,
        ]);
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 3033, 'customer_id' => $cliente->id, 'ragione_sociale' => 'Hotel Marco Polo',
            'anno' => 2026, 'numero_fattura' => '99',
            'data_fattura' => '2026-05-01', 'data_scadenza' => '2026-05-31', 'saldo' => 300.00,
        ]);

        // Fattura del 2023 CON numero: e' un credito vero e deve comparire.
        // Filtrando per anno spariva, e il cliente risultava dovere meno di
        // quanto deve (caso reale: Pasti Fabio, fattura 513 del 15/12/2023).
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 1366, 'ragione_sociale' => 'Pasti Fabio',
            'anno' => 2023, 'numero_fattura' => '513',
            'data_fattura' => '2023-12-15', 'data_scadenza' => '2023-12-15', 'saldo' => 1658.83,
        ]);

        // Scrittura di apertura: nessun numero di fattura, non e' esigibile
        // verso un documento preciso e non deve comparire.
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 1368, 'ragione_sociale' => 'Riporto Apertura',
            'anno' => 2023, 'numero_fattura' => null,
            'data_fattura' => '2023-01-01', 'data_scadenza' => null, 'saldo' => 738.47,
        ]);

        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 19, 'ragione_sociale' => 'Nota di credito',
            'anno' => 2026, 'numero_fattura' => '44',
            'data_fattura' => '2026-06-01', 'data_scadenza' => '2026-06-01', 'saldo' => -50.00,
        ]);

        // Nota di credito DELLO STESSO cliente che ha la fattura scaduta:
        // l'elenco deve chiedergli 180, non 300. Scadenza futura di
        // proposito, perche' un credito vale comunque.
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 3033, 'customer_id' => $cliente->id, 'ragione_sociale' => 'Hotel Marco Polo',
            'anno' => 2026, 'numero_fattura' => '100',
            'data_fattura' => '2026-06-10', 'data_scadenza' => '2099-01-01', 'saldo' => -120.00,
        ]);

        // Credito piu' grande del debito: non c'e' niente da chiedergli.
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 77, 'ragione_sociale' => 'Solo Crediti',
            'anno' => 2026, 'numero_fattura' => '7',
            'data_fattura' => '2026-01-01', 'data_scadenza' => '2026-01-31', 'saldo' => 40.00,
        ]);
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 77, 'ragione_sociale' => 'Solo Crediti',
            'anno' => 2026, 'numero_fattura' => '8',
            'data_fattura' => '2026-01-02', 'data_scadenza' => '2026-02-28', 'saldo' => -90.00,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Il riepilogo aggrega per cliente: due partite dello stesso
        // cliente devono comparire come UNA riga.
        $riepilogo = Livewire::test(ScadutoClienti::class)
            ->assertOk()
            ->assertSee('Hotel Marco Polo')
            ->assertSee('Pasti Fabio')
            ->assertDontSee('Riporto Apertura');

        // I giorni si mostrano interi: diffInDays() di Carbon torna un float,
        // e senza cast in colonna compariva "397.73471022177 giorni".
        $this->assertDoesNotMatchRegularExpression(
            '/\d+[.,]\d+\s*giorni/',
            $riepilogo->html(),
            'i giorni di ritardo non devono avere decimali',
        );

        // Il dettaglio mostra le fatture di quel cliente, note di credito
        // comprese.
        Livewire::test(DettaglioScaduto::class, ['codice' => 3033])
            ->assertOk()
            ->assertSee('99');

        // Netto, non lordo: Hotel Marco Polo ha 300 di fattura scaduta e una
        // nota di credito da 120, quindi al telefono gli si chiedono 180.
        $riepilogo->assertSee('180,00');

        // Chi ha piu' credito che debito non e' qualcuno da chiamare.
        $riepilogo->assertDontSee('Solo Crediti');

        // Ordinamenti: il default resta il peso (importo x ritardo), che
        // mette in cima Pasti Fabio; cliccando "Cliente" comanda l'alfabeto.
        $this->assertSame(
            [1366, 18, 3033],
            Livewire::test(ScadutoClienti::class)->instance()->getTableRecords()
                ->pluck('gestionale_code')->all(),
            'senza ordinamento scelto comanda il peso',
        );

        $this->assertSame(
            [18, 3033, 1366],
            Livewire::test(ScadutoClienti::class)
                ->sortTable('ragione_sociale')
                ->instance()->getTableRecords()
                ->pluck('gestionale_code')->all(),
            'ordinando per cliente comanda l\'alfabeto',
        );

        // "Ferma da" mostra giorni, la query ordina per data: crescente
        // significa il ritardo PIU CORTO in cima, non la data piu vecchia.
        $this->assertSame(
            3033,
            Livewire::test(ScadutoClienti::class)
                ->sortTable('piu_vecchia')
                ->instance()->getTableRecords()
                ->first()->gestionale_code,
            'ordinando per "Ferma da" crescente viene prima chi aspetta da meno',
        );

        Livewire::test(ScadutoOverviewWidget::class)
            ->assertOk()
            // Nel riquadro debiti e crediti NON si compensano, al contrario
            // della tabella sotto: 174,92 + 300,00 + 1.658,83 + 40,00 da
            // incassare, 260,00 di crediti mostrati a parte. Il riporto di
            // apertura da 738,47 resta fuori perche' privo di numero fattura.
            ->assertSee('2.173,75')
            ->assertSee('260,00');
    }

    /**
     * Tre diciture che si smentivano da sole, e un riquadro che si contraddice
     * toglie fiducia anche ai numeri giusti che ha accanto.
     */
    public function test_il_riquadro_non_dice_cose_che_i_dati_smentiscono(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        // Staff master: le pagine contabili non passano piu' dai ruoli,
        // sono riservate a is_super_admin (vedi il loro canAccess()).
        $user->update(['is_super_admin' => true]);

        // Scaduta da un pezzo, del 2023.
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 1366, 'ragione_sociale' => 'Pasti Fabio',
            'anno' => 2023, 'numero_fattura' => '513',
            'data_fattura' => '2023-12-15', 'data_scadenza' => '2023-12-15', 'saldo' => 990.00,
        ]);

        // Aperta ma NON ancora scaduta: e' cio' che impedisce al totale
        // scaduto di essere il 100% del totale da incassare.
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 1367, 'ragione_sociale' => 'Non Ancora Scaduta',
            'anno' => 2026, 'numero_fattura' => '600',
            'data_fattura' => '2026-08-01', 'data_scadenza' => '2099-01-01', 'saldo' => 10.00,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        $riquadro = Livewire::test(ScadutoOverviewWidget::class)->assertOk();

        // L'anno piu' vecchio si legge dai dati: era scritto a mano "dal
        // 2024" mentre in elenco ci sono fatture del 2023.
        $riquadro->assertSee('dal 2023')->assertDontSee('dal 2024');

        // 990 su 1000 e' il 99%: arrotondato a 100 il riquadro dichiarava
        // che TUTTO e' scaduto mentre 10 euro non lo erano.
        $riquadro->assertSee('99% del totale');

        // Le note di credito hanno il loro posto e non finiscono nel ritardo:
        // la colonna mostrava una cella vuota perche' Filament salta
        // formatStateUsing quando lo stato e' null.
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 1366, 'ragione_sociale' => 'Pasti Fabio',
            'anno' => 2026, 'numero_fattura' => '700',
            'data_fattura' => '2026-07-01', 'data_scadenza' => null, 'saldo' => -40.00,
        ]);

        Livewire::test(DettaglioScaduto::class, ['codice' => 1366])
            ->assertOk()
            ->assertSee('nota di credito');
    }
}
