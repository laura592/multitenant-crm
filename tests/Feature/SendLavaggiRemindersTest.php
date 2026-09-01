<?php

namespace Tests\Feature;

use App\Mail\LavaggiDigestMail;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendLavaggiRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex-test',
            'notify_lavaggio_emails' => ['s.alessandro@alexcaffe.com'],
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'company_name' => 'Bar Centrale',
        ]);
    }

    private function schedule(array $attributes = []): MaintenanceSchedule
    {
        return MaintenanceSchedule::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'status' => MaintenanceSchedule::STATUS_ATTIVO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_BIRRA,
            'frequency_days' => 30,
        ], $attributes));
    }

    public function test_il_digest_elenca_i_lavaggi_scaduti_e_quelli_in_scadenza(): void
    {
        Mail::fake();

        $scaduto = $this->schedule(['next_due_date' => now()->subDays(10)]);
        $inScadenza = $this->schedule(['next_due_date' => now()->addDays(3)]);

        $this->artisan('lavaggi:send-reminders')->assertSuccessful();

        Mail::assertSent(LavaggiDigestMail::class, function (LavaggiDigestMail $mail) use ($scaduto, $inScadenza) {
            return $mail->hasTo('s.alessandro@alexcaffe.com')
                && $mail->schedules->pluck('id')->all() === [$scaduto->id, $inScadenza->id];
        });
    }

    public function test_restano_fuori_i_piani_lontani_chiusi_a_chiamata_o_di_manutenzione(): void
    {
        Mail::fake();

        // L'unico che deve far partire la mail.
        $this->schedule(['next_due_date' => now()->subDay()]);

        $lontano = $this->schedule(['next_due_date' => now()->addDays(20)]);
        $chiuso = $this->schedule([
            'status' => MaintenanceSchedule::STATUS_CHIUSO,
            'next_due_date' => now()->subDays(30),
        ]);
        // Piano "a chiamata": nessuna cadenza, quindi nessuna scadenza da
        // rispettare - non e' in ritardo, aspetta il cliente.
        $aChiamata = $this->schedule(['frequency_days' => null, 'next_due_date' => null]);
        $manutenzione = $this->schedule([
            'type' => MaintenanceSchedule::TYPE_MANUTENZIONE,
            'next_due_date' => now()->subDays(5),
        ]);

        $this->artisan('lavaggi:send-reminders')->assertSuccessful();

        Mail::assertSent(LavaggiDigestMail::class, function (LavaggiDigestMail $mail) use ($lontano, $chiuso, $aChiamata, $manutenzione) {
            $ids = $mail->schedules->pluck('id')->all();

            return count($ids) === 1
                && ! in_array($lontano->id, $ids, true)
                && ! in_array($chiuso->id, $ids, true)
                && ! in_array($aChiamata->id, $ids, true)
                && ! in_array($manutenzione->id, $ids, true);
        });
    }

    public function test_nessuna_mail_senza_destinatari_configurati(): void
    {
        Mail::fake();

        $this->tenant->update(['notify_lavaggio_emails' => []]);
        $this->schedule(['next_due_date' => now()->subDays(10)]);

        $this->artisan('lavaggi:send-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_nessuna_mail_se_non_c_e_niente_da_lavare(): void
    {
        Mail::fake();

        $this->schedule(['next_due_date' => now()->addDays(20)]);

        $this->artisan('lavaggi:send-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_la_finestra_di_anticipo_e_configurabile(): void
    {
        Mail::fake();

        $this->schedule(['next_due_date' => now()->addDays(20)]);

        $this->artisan('lavaggi:send-reminders', ['--days' => 30])->assertSuccessful();

        Mail::assertSent(LavaggiDigestMail::class, fn (LavaggiDigestMail $mail) => $mail->schedules->count() === 1);
    }

    public function test_il_digest_si_renderizza_con_cliente_impianto_e_ultimo_lavaggio(): void
    {
        $schedule = $this->schedule(['lines_count' => 5]);

        Lavaggio::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(45),
            'descrizione' => '5 vie',
        ]);

        $schedule->refresh();
        $this->assertTrue($schedule->next_due_date->isPast());

        $rendered = (new LavaggiDigestMail(
            $this->tenant,
            MaintenanceSchedule::with(['customer', 'lastLavaggio'])->whereKey($schedule->id)->get(),
            7,
        ))->render();

        $this->assertStringContainsString('Bar Centrale', $rendered);
        $this->assertStringContainsString('Birra', $rendered);
        $this->assertStringContainsString('5 vie', $rendered);
        $this->assertStringContainsString(now()->subDays(45)->format('d/m/Y'), $rendered);
        $this->assertStringContainsString('Scaduto', $rendered);
    }
}
