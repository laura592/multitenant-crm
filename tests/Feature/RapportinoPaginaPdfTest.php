<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ViewServiceReport;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La scelta fra copia con e senza prezzi deve esserci anche sulla pagina del
 * singolo rapportino, non solo nell'elenco: e' li' che si finisce quando si
 * sta guardando un intervento, ed e' li' che si stampa.
 *
 * Le due voci erano state aggiunte solo alla tabella, e chi apriva il
 * rapportino trovava un solo PDF (segnalato dal vivo il 02/09/2026).
 */
class RapportinoPaginaPdfTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function apri(string $ruolo): ServiceReport
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Utente', 'email' => 'u@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($utente, $tenant, $ruolo);
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Hotel Neps']);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'technician_id' => $utente->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2026-05-01',
        ]);

        $this->actingAs($utente);
        Filament::setTenant($tenant);

        return $report;
    }

    public function test_amministrazione_sceglie_fra_le_due_copie(): void
    {
        $report = $this->apri('amministrazione');

        Livewire::test(ViewServiceReport::class, ['record' => $report->getKey()])
            ->assertActionVisible('pdf')
            ->assertActionVisible('pdf_senza_prezzi');
    }

    /** Al dipendente resta una sola voce, ed e' quella senza prezzi. */
    public function test_al_dipendente_la_copia_con_i_prezzi_non_compare(): void
    {
        $report = $this->apri('dipendente');

        Livewire::test(ViewServiceReport::class, ['record' => $report->getKey()])
            ->assertActionHidden('pdf')
            ->assertActionVisible('pdf_senza_prezzi');
    }
}
