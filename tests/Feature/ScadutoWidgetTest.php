<?php

namespace Tests\Feature;

use App\Filament\Pages\DettaglioScaduto;
use App\Filament\Pages\ScadutoClienti;
use App\Filament\Widgets\Contabilita\ScadutoOverviewWidget;
use App\Models\Customer;
use App\Models\EurekaPartitaAperta;
use App\Models\EurekaSaldoAnagrafica;
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

        // Scrittura di apertura: nessun numero di fattura, nessuna
        // scadenza. E' un credito vero (dal 23/09/2026 entra nell'elenco) e
        // la sua scadenza e' la data del riporto.
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
            // Chi ha solo il riporto di apertura e' comunque qualcuno da
            // chiamare: prima spariva dall'elenco e i suoi 738,47 non li
            // chiedeva nessuno.
            ->assertSee('Riporto Apertura')
            ->assertSee('riporto di apertura € 738,47');

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
            [1366, 1368, 18, 3033],
            Livewire::test(ScadutoClienti::class)->instance()->getTableRecords()
                ->pluck('gestionale_code')->all(),
            'senza ordinamento scelto comanda il peso',
        );

        $this->assertSame(
            [18, 3033, 1366, 1368],
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
            // In "Da incassare" debiti e crediti NON si compensano, al
            // contrario della tabella sotto: 174,92 + 300,00 + 1.658,83 +
            // 40,00 + 738,47 di riporto da incassare, 260,00 di crediti
            // mostrati a parte.
            ->assertSee('2.912,22')
            ->assertSee('260,00')
            // "Saldo clienti" e' il netto, cioe' il numero del gestionale:
            // 2.912,22 - 260,00 = 2.652,22.
            ->assertSee('2.652,22')
            ->assertSee('riporti compresi');
    }

    /**
     * Gli incassi che Eureka non abbina a nessuna fattura sono soldi gia'
     * arrivati. Finche' l'elenco li scartava (stesso filtro dei riporti:
     * niente numero di fattura) capitava di chiamare un cliente per
     * chiedergli quello che aveva gia' pagato — caso reale, Pizzeria la
     * Strana Coppia, data da chiamare per 335,74 mentre il gestionale la
     * dava a credito di 118,01.
     */
    public function test_gli_incassi_non_imputati_abbassano_quello_che_si_chiede(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $user->update(['is_super_admin' => true]);

        $partita = function (array $dati) use ($tenant) {
            EurekaPartitaAperta::create($dati + [
                'tenant_id' => $tenant->id,
                'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
                'anno' => 2026,
            ]);
        };

        // Ha pagato piu' di quanto deve: sparisce dall'elenco.
        $partita(['gestionale_code' => 1423, 'ragione_sociale' => 'Strana Coppia', 'numero_fattura' => '428',
            'data_fattura' => '2026-09-01', 'data_scadenza' => '2026-09-01', 'saldo' => 391.13]);
        $partita(['gestionale_code' => 1423, 'ragione_sociale' => 'Strana Coppia', 'numero_fattura' => null,
            'data_fattura' => '2026-06-10', 'data_scadenza' => null, 'saldo' => -195.84]);
        $partita(['gestionale_code' => 1423, 'ragione_sociale' => 'Strana Coppia', 'numero_fattura' => null,
            'data_fattura' => '2026-07-09', 'data_scadenza' => null, 'saldo' => -257.91]);

        // Ha pagato in parte: resta in elenco, ma per la differenza.
        $partita(['gestionale_code' => 900, 'ragione_sociale' => 'Pagamento Parziale', 'numero_fattura' => '12',
            'data_fattura' => '2026-01-10', 'data_scadenza' => '2026-01-31', 'saldo' => 500.00]);
        $partita(['gestionale_code' => 900, 'ragione_sociale' => 'Pagamento Parziale', 'numero_fattura' => null,
            'data_fattura' => '2026-02-10', 'data_scadenza' => null, 'saldo' => -100.00]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        $elenco = Livewire::test(ScadutoClienti::class)->assertOk();

        $elenco->assertDontSee('Strana Coppia');
        $elenco->assertSee('Pagamento Parziale')
            ->assertSee('400,00')
            // Un incasso non e' una nota di credito: chiamarlo cosi' manda
            // il cliente a cercare un documento che non esiste.
            ->assertSee('di incassi non imputati');

        $this->assertSame(
            [900],
            Livewire::test(ScadutoClienti::class)->instance()->getTableRecords()
                ->pluck('gestionale_code')->all(),
            'chi ha gia'."'".' pagato non e'."'".' qualcuno da chiamare',
        );
    }

    /**
     * Il saldo complessivo e' l'unico numero della pagina confrontabile con
     * l'estratto conto del gestionale: se non torna col saldo che Eureka
     * dichiara per anagrafica, il riquadro lo dichiara invece di lasciare
     * che se ne accorga chi legge.
     */
    public function test_il_saldo_clienti_segnala_quando_eureka_dice_un_altro_numero(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $user->update(['is_super_admin' => true]);

        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 18, 'ragione_sociale' => 'A & A SNC',
            'anno' => 2026, 'numero_fattura' => '43',
            'data_fattura' => '2026-02-28', 'data_scadenza' => '2026-02-28', 'saldo' => 1000.00,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Senza saldi dichiarati non c'e' niente da confrontare: nessun
        // allarme, e soprattutto nessun "Eureka ne dichiara € 0,00".
        Livewire::test(ScadutoOverviewWidget::class)
            ->assertOk()
            ->assertSee('1.000,00')
            ->assertDontSee('Eureka ne dichiara');

        EurekaSaldoAnagrafica::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 18, 'ragione_sociale' => 'A & A SNC', 'saldo' => 1200.00,
        ]);

        Livewire::test(ScadutoOverviewWidget::class)
            ->assertOk()
            ->assertSee('Eureka ne dichiara € 1.200,00');

        // Allineati: il riquadro tace, non festeggia.
        EurekaSaldoAnagrafica::query()->update(['saldo' => 1000.00]);

        Livewire::test(ScadutoOverviewWidget::class)
            ->assertOk()
            ->assertDontSee('Eureka ne dichiara');
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
