<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B20359 (22/09/2026): al Majer S. Agostino "dal 14/01/2026, bolla n. 247",
 * che e' la consegna alla Grigliata. La sua e' la n. 189 del 31/01/2024.
 */
class CorreggiDateMacchineImportateTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_posizione_importata_prende_la_bolla_del_suo_cliente(): void
    {
        Http::preventStrayRequests();
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $majer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Majer S. Agostino', 'gestionale_code' => 1149]);
        $manuale = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar a mano', 'gestionale_code' => 7]);

        $m = MachineUnit::create(['tenant_id' => $tenant->id, 'serial_number' => 'B20359', 'model_name' => 'Addolcitore LT 8']);
        $m->moveTo($majer, 'Importata da Eureka, articolo ADDOLC.LT8, bolla n. 247', Carbon::parse('2026-01-14'));
        $altra = MachineUnit::create(['tenant_id' => $tenant->id, 'serial_number' => 'X1', 'model_name' => 'Faema']);
        $altra->moveTo($manuale, 'Spostata a mano', Carbon::parse('2025-03-01'));

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(match ((int) ($q['q'] ?? 0)) {
                1149 => [['matricola' => 'B20359', 'numero_doc_t23' => 189, 'data_documento' => '2024-01-31T00:00:00.000+01:00']],
                7 => [['matricola' => 'X1', 'numero_doc_t23' => 5, 'data_documento' => '2020-01-01T00:00:00.000+01:00']],
                default => [],
            }, 200);
        });

        $this->artisan('macchine:correggi-date-importate', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('2026-01-14', $m->placements()->sole()->placed_at->toDateString(), 'In prova non scrive.');

        $this->artisan('macchine:correggi-date-importate')
            ->expectsConfirmation('Correggo 1 posizioni?', 'yes')
            ->assertSuccessful();

        $p = $m->placements()->sole();
        $this->assertSame('2024-01-31', $p->placed_at->toDateString());
        $this->assertSame('Importata da Eureka, articolo ADDOLC.LT8, bolla n. 189', $p->notes);
        $this->assertSame('2025-03-01', $altra->placements()->sole()->placed_at->toDateString(), 'Le posizioni registrate a mano non si toccano.');

        $this->artisan('macchine:correggi-date-importate')->expectsOutputToContain('sono giuste')->assertSuccessful();
    }
}
