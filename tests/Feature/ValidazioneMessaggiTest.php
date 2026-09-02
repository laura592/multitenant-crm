<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\CreateServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il salvataggio bloccato da un campo obbligatorio non dava nessun segnale
 * utile: il messaggio sotto al campo era la chiave nuda "validation.required"
 * (APP_LOCALE=it senza cartella lang/), e su un form lungo l'errore restava
 * fuori schermo. Chi premeva "Salva" vedeva solo il bottone girare un istante
 * e concludeva che il pulsante non funzionasse.
 */
class ValidazioneMessaggiTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_i_messaggi_di_validazione_sono_in_italiano(): void
    {
        $this->assertSame(
            'Il campo Cliente è obbligatorio.',
            trans('validation.required', ['attribute' => 'Cliente'])
        );

        // Non deve tornare la chiave nuda per nessuna regola usata nei form.
        foreach (['required', 'email', 'numeric', 'max.string', 'unique', 'date'] as $regola) {
            $this->assertStringNotContainsString('validation.', trans("validation.{$regola}"));
        }
    }

    public function test_il_salvataggio_bloccato_lo_dice_con_un_avviso(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tec@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Nessun cliente, nessun lavoro svolto: il form si ferma.
        Livewire::test(CreateServiceReport::class)
            ->fillForm(['intervention_date' => now()])
            ->call('create')
            ->assertHasFormErrors();

        Notification::assertNotified('Non salvato: controlla i campi in rosso');
    }
}
