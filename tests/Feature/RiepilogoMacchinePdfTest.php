<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource\Pages\ListMachineUnits;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La stampa del riepilogo segue l'elenco che si ha davanti: senza filtri
 * esce il parco completo, cercando un cliente escono le sue macchine.
 * Un'azione sola invece di due che fanno quasi la stessa cosa.
 */
class RiepilogoMacchinePdfTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /** @return array{0: Tenant, 1: Customer} */
    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@alex.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $tenant, 'admin');

        $modello = Material::create([
            'tenant_id' => $tenant->id, 'code' => 'EVOA2', 'category' => 'Eureka',
            'type' => 'MACCHINA CAFFE DALLA CORTE EVO A/2', 'source' => Material::SOURCE_EUREKA,
            'maintenance_code' => 'DC2',
        ]);

        $porto = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar del Porto', 'city' => 'Jesolo']);
        $centro = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Caffe Centrale', 'city' => 'Mestre']);

        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $porto->id, 'material_id' => $modello->id,
            'serial_number' => 'SN-PORTO-1', 'model_name' => 'Dalla Corte EVO A/2',
        ]);
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $centro->id, 'material_id' => $modello->id,
            'serial_number' => 'SN-CENTRO-1', 'model_name' => 'Dalla Corte EVO A/2',
        ]);

        $this->actingAs($utente);
        Filament::setTenant($tenant);

        return [$tenant, $porto];
    }

    public function test_senza_filtri_stampa_tutto_il_parco(): void
    {
        $this->scenario();

        Livewire::test(ListMachineUnits::class)
            ->callAction('stampa_riepilogo')
            ->assertFileDownloaded('riepilogo-macchine-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Il contenuto lo si verifica sulla view, non sul PDF compilato: dompdf
     * comprime lo stream e cercarci dentro una matricola non direbbe niente.
     */
    public function test_il_foglio_mostra_matricola_codice_manutenzione_e_cliente(): void
    {
        [$tenant] = $this->scenario();

        $html = view('pdf.machine-units', [
            'macchine' => MachineUnit::with(['currentCustomer', 'material'])->get(),
            'tenant' => $tenant,
            'data' => now(),
            'titolo' => 'Parco macchine completo',
            'etichetteStato' => \App\Filament\Resources\MachineUnitResource::statusLabels(),
            'etichetteCategoria' => \App\Filament\Resources\MachineUnitResource::typeLabels(),
        ])->render();

        $this->assertStringContainsString('SN-PORTO-1', $html);
        $this->assertStringContainsString('SN-CENTRO-1', $html);
        // Il codice manutenzione del modello: e' il motivo per cui il
        // tecnico si porta dietro il foglio.
        $this->assertStringContainsString('DC2', $html);
        $this->assertStringContainsString('Bar del Porto', $html);
        $this->assertStringContainsString('2 macchine', $html);
    }

    public function test_cercando_un_cliente_escono_solo_le_sue(): void
    {
        $this->scenario();

        $componente = Livewire::test(ListMachineUnits::class)
            ->set('tableSearch', 'Bar del Porto');

        $matricole = $componente->instance()
            ->getFilteredSortedTableQuery()
            ->pluck('serial_number')
            ->all();

        $this->assertSame(['SN-PORTO-1'], $matricole);
    }
}
