<?php

namespace Tests\Feature;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\ServiceReportResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I permessi nostri sui rapportini devono comparire nella schermata dei
 * ruoli. Non c'erano, e chi modificava un ruolo dal pannello li perdeva
 * senza accorgersene: e' cosi' che "vedere i prezzi" e' sparito dal ruolo
 * admin (04/09/2026) e poi da amministrazione (22/09/2026: Cristina non
 * vedeva piu' le fatture collegate ai rapportini).
 */
class PermessiRapportiniNellaSchermataRuoliTest extends TestCase
{
    use RefreshDatabase;

    public function test_i_permessi_nostri_dei_rapportini_si_vedono_e_hanno_un_nome_in_italiano(): void
    {
        $prefissi = Utils::getResourcePermissionPrefixes(ServiceReportResource::class);

        foreach (['view_prices', 'send_to_gestionale', 'send_email', 'send_email_completo'] as $nostro) {
            $this->assertContains($nostro, $prefissi, "{$nostro} non arriva alla schermata dei ruoli");
        }

        $opzioni = RoleResource::getResourcePermissionOptions([
            'resource' => 'service::report',
            'fqcn' => ServiceReportResource::class,
        ]);

        $this->assertSame('Vedere i prezzi', $opzioni['view_prices_service::report'] ?? null);
        $this->assertSame('Inviare a Eureka', $opzioni['send_to_gestionale_service::report'] ?? null);
        $this->assertSame('Inviare al cliente', $opzioni['send_email_service::report'] ?? null);
        $this->assertSame('Scegliere la copia da inviare', $opzioni['send_email_completo_service::report'] ?? null);
        $this->assertArrayHasKey('view_any_service::report', $opzioni, 'Restano anche quelli standard.');
    }
}
