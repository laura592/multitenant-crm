<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\CreateServiceReport;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Scegliere l'impianto in una sanificazione deve portare da solo le voci da
 * fatturare (LAV2, e ULTVIA oltre le due vie).
 *
 * Le vie si riempivano da sole scegliendo il piano, ma scriverle con $set non
 * risveglia l'afterStateUpdated del repeater: quello scatta solo se le vie le
 * digita una persona e poi esce dal campo. Chi sceglieva l'impianto e si
 * fermava li' restava senza righe da fatturare, e non c'era niente che glielo
 * dicesse (segnalato dal vivo il 03/09/2026).
 */
class LavaggioRicambiAutomaticiTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function scenario(int $vie, ?string $bevanda = null): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($utente, $tenant, 'admin');

        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $macchina = MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $cliente->id,
            'serial_number' => 'IMP-SPINA-001', 'model_name' => 'Impianto Spina',
        ]);

        foreach (['LAV2' => 'Lavaggio', 'ULTVIA' => 'Via ulteriore', 'SANIFICAZIONE' => 'Sanificazione impianto acqua'] as $codice => $tipo) {
            Material::create([
                'code' => $codice, 'source' => Material::SOURCE_EUREKA, 'tenant_id' => $tenant->id,
                'category' => 'Eureka', 'type' => $tipo, 'list_price' => 10,
            ]);
        }

        $piano = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'machine_unit_id' => $macchina->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO, 'status' => MaintenanceSchedule::STATUS_ATTIVO,
            'lines_count' => $vie, 'frequency' => 'mensile',
            'beverage_type' => $bevanda,
        ]);

        $this->actingAs($utente);
        Filament::setTenant($tenant);

        return [$cliente, $piano, $utente];
    }

    /** @return array<int, string> i codici materiale finiti nel form */
    private function codiciNelForm(array $stato): array
    {
        return collect($stato['materialsUsed'] ?? [])
            ->map(fn (array $riga) => Material::find($riga['material_id'] ?? null)?->code)
            ->filter()->values()->all();
    }

    public function test_scegliere_l_impianto_porta_la_voce_di_lavaggio(): void
    {
        [$cliente, $piano, $utente] = $this->scenario(vie: 2);

        $form = Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $cliente->id,
                'technician_id' => $utente->id,
                'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
                'lavaggio_impianti' => [['maintenance_schedule_id' => $piano->id]],
            ]);

        $stato = $form->instance()->form->getRawState();

        $this->assertContains('LAV2', $this->codiciNelForm($stato), 'la voce di lavaggio deve comparire da sola');
        $this->assertSame(2, (int) ($stato['lavaggio_vie_count'] ?? 0));
        $this->assertTrue((bool) ($stato['_lavaggio_vie_eseguito'] ?? false));
    }

    /** Oltre le due vie si aggiunge la voce delle vie ulteriori. */
    public function test_oltre_due_vie_arriva_anche_la_via_ulteriore(): void
    {
        [$cliente, $piano, $utente] = $this->scenario(vie: 5);

        $form = Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $cliente->id,
                'technician_id' => $utente->id,
                'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
                'lavaggio_impianti' => [['maintenance_schedule_id' => $piano->id]],
            ]);

        $stato = $form->instance()->form->getRawState();
        $codici = $this->codiciNelForm($stato);

        $this->assertContains('LAV2', $codici);
        $this->assertContains('ULTVIA', $codici);

        $ultvia = collect($stato['materialsUsed'])
            ->first(fn (array $r) => Material::find($r['material_id'])?->code === 'ULTVIA');
        // Cinque vie: due nella voce base, tre ulteriori.
        $this->assertSame(3, (int) $ultvia['quantity']);
    }

    /**
     * Un impianto acqua non si lava a vie: si sanifica, e la voce da
     * fatturare e' SANIFICAZIONE IMPIANTO ACQUA (110,00), non LAVAGGIO 2 VIE
     * (38,00). Prima prendeva la seconda come qualunque altro impianto.
     */
    public function test_un_impianto_acqua_prende_la_sanificazione_non_il_lavaggio_vie(): void
    {
        [$cliente, $piano, $utente] = $this->scenario(vie: 1, bevanda: MaintenanceSchedule::BEVERAGE_ACQUA);

        $form = Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $cliente->id,
                'technician_id' => $utente->id,
                'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
                'lavaggio_impianti' => [['maintenance_schedule_id' => $piano->id]],
            ]);

        $stato = $form->instance()->form->getRawState();
        $codici = $this->codiciNelForm($stato);

        $this->assertContains('SANIFICAZIONE', $codici, 'un impianto acqua deve portare la sanificazione');
        $this->assertNotContains('LAV2', $codici, 'un impianto acqua non si fattura a vie');
        $this->assertNotContains('ULTVIA', $codici);
        // Niente vie: il toggle "Lavaggio eseguito" resta spento.
        $this->assertFalse((bool) ($stato['_lavaggio_vie_eseguito'] ?? false));
    }

    /**
     * Un rapportino puo' avere entrambe le cose: l'impianto birra si conta a
     * vie, quello acqua si sanifica, e le vie dell'acqua non devono gonfiare
     * il conteggio dell'altro.
     */
    public function test_acqua_e_birra_insieme_non_si_mescolano(): void
    {
        [$cliente, $birra, $utente] = $this->scenario(vie: 3, bevanda: MaintenanceSchedule::BEVERAGE_BIRRA);

        $acqua = MaintenanceSchedule::create([
            'tenant_id' => $birra->tenant_id, 'customer_id' => $cliente->id,
            'machine_unit_id' => $birra->machine_unit_id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO, 'status' => MaintenanceSchedule::STATUS_ATTIVO,
            'lines_count' => 1, 'frequency' => 'mensile',
            'beverage_type' => MaintenanceSchedule::BEVERAGE_ACQUA,
        ]);

        $form = Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $cliente->id,
                'technician_id' => $utente->id,
                'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
                'lavaggio_impianti' => [
                    ['maintenance_schedule_id' => $birra->id],
                    ['maintenance_schedule_id' => $acqua->id],
                ],
            ]);

        $stato = $form->instance()->form->getRawState();
        $codici = $this->codiciNelForm($stato);

        $this->assertContains('LAV2', $codici);
        $this->assertContains('SANIFICAZIONE', $codici);
        // Tre vie dalla birra, non quattro: la via dell'acqua non si somma.
        $this->assertSame(3, (int) ($stato['lavaggio_vie_count'] ?? 0));

        $ultvia = collect($stato['materialsUsed'])
            ->first(fn (array $r) => Material::find($r['material_id'])?->code === 'ULTVIA');
        $this->assertSame(1, (int) $ultvia['quantity']);
    }
}
