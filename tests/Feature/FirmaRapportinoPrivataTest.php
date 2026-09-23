<?php

namespace Tests\Feature;

use App\Filament\Forms\Components\SignaturePad;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La firma del cliente su un rapportino e' un dato personale: fino al
 * 23/09/2026 finiva su storage/app/public, che /storage serve a chiunque
 * senza login — il nome del file era un UUID, ma "difficile da indovinare"
 * non e' un controllo d'accesso. Ora sta sul disco privato e si apre solo
 * dalla rotta service-reports.firma, che chiede il permesso.
 */
class FirmaRapportinoPrivataTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function rapportinoFirmato(Tenant $tenant, string $firma = 'signatures/prova.png'): ServiceReport
    {
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar '.$tenant->name]);
        $tecnico = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico-'.$tenant->slug.'@example.com', 'password' => bcrypt('x'),
        ]);

        Storage::disk('local')->put($firma, self::pngMinimo());

        return ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tecnico->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Intervento di prova',
            'customer_signature_path' => $firma,
        ]);
    }

    public function test_il_campo_firma_scrive_sul_disco_privato_non_su_quello_pubblico(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $campo = SignaturePad::make('customer_signature_path');

        $this->assertSame('local', $campo->getDisk());
    }

    public function test_chi_puo_vedere_il_rapportino_apre_la_firma(): void
    {
        Storage::fake('local');
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $utente = User::create(['tenant_id' => $tenant->id, 'name' => 'U', 'email' => 'u@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($utente, $tenant, 'admin');
        $rapportino = $this->rapportinoFirmato($tenant);

        $risposta = $this->actingAs($utente)->get(route('service-reports.firma', $rapportino));

        $risposta->assertOk();
        $this->assertSame('image/png', $risposta->headers->get('Content-Type'));
    }

    public function test_un_altro_tenant_non_apre_la_firma(): void
    {
        Storage::fake('local');
        $mio = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $altro = Tenant::create(['name' => 'Altro', 'slug' => 'altro']);

        $utente = User::create(['tenant_id' => $mio->id, 'name' => 'U', 'email' => 'u@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($utente, $mio, 'admin');

        $rapportino = $this->rapportinoFirmato($altro, 'signatures/altro.png');

        $this->actingAs($utente)
            ->get(route('service-reports.firma', $rapportino))
            ->assertForbidden();
    }

    public function test_senza_login_la_firma_non_si_apre(): void
    {
        Storage::fake('local');
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $rapportino = $this->rapportinoFirmato($tenant);

        $this->get(route('service-reports.firma', $rapportino))
            // /login e' la scorciatoia che porta al login del pannello (routes/web.php).
            ->assertRedirect(route('login'));
    }

    /**
     * Le firme raccolte prima del cambio stanno ancora sul disco pubblico:
     * devono continuare a vedersi finche' non gira firme:porta-al-privato.
     */
    public function test_le_firme_vecchie_sul_disco_pubblico_si_vedono_ancora(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $utente = User::create(['tenant_id' => $tenant->id, 'name' => 'U', 'email' => 'u@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($utente, $tenant, 'admin');

        $rapportino = $this->rapportinoFirmato($tenant, 'signatures/vecchia.png');

        // Come stava prima: solo sul pubblico.
        Storage::disk('public')->put('signatures/vecchia.png', self::pngMinimo());
        Storage::disk('local')->delete('signatures/vecchia.png');

        $this->actingAs($utente)
            ->get(route('service-reports.firma', $rapportino))
            ->assertOk();
    }

    public function test_il_comando_sposta_le_firme_e_ripulisce_il_pubblico(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        Storage::disk('public')->put('signatures/una.png', self::pngMinimo());
        Storage::disk('public')->put('signatures/due.png', self::pngMinimo());

        // Senza --applica non tocca niente.
        $this->artisan('firme:porta-al-privato')->assertSuccessful();
        $this->assertTrue(Storage::disk('public')->exists('signatures/una.png'));
        $this->assertFalse(Storage::disk('local')->exists('signatures/una.png'));

        $this->artisan('firme:porta-al-privato --applica')
            ->expectsConfirmation('Sposto 2 firme sul disco privato e le tolgo dal pubblico?', 'yes')
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists('signatures/una.png'));
        $this->assertTrue(Storage::disk('local')->exists('signatures/due.png'));
        $this->assertFalse(Storage::disk('public')->exists('signatures/una.png'));
        $this->assertFalse(Storage::disk('public')->exists('signatures/due.png'));
    }

    /** Un PNG 1x1 valido: basta a far esistere un file d'immagine vero. */
    private static function pngMinimo(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
