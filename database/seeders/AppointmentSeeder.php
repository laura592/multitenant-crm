<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Appuntamenti di esempio sul calendario tecnici (pagina "Appuntamenti"),
 * sparsi tra passato prossimo e prossime settimane cosi' il calendario non
 * appare vuoto alla prima demo. Usa i tecnici reali gia' presenti (o
 * l'utente di test "dipendente" come riserva) e clienti gia' importati.
 * Idempotente: updateOrCreate su tenant+titolo+data inizio.
 */
class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'alex')->first();

        if (! $tenant) {
            return;
        }

        $technicians = User::where('tenant_id', $tenant->id)
            ->whereIn('email', ['lauragrb.1990@gmail.com', 's.alessandro@alexcaffe.com', 'dipendente@test.it'])
            ->get()
            ->unique('id')
            ->values();

        $customers = Customer::where('tenant_id', $tenant->id)->inRandomOrder()->limit(6)->get();

        if ($technicians->isEmpty() || $customers->isEmpty()) {
            return;
        }

        $plans = [
            ['title' => 'Installazione macchina caffè', 'days' => -6, 'hour' => 9, 'duration' => 2, 'status' => Appointment::STATUS_COMPLETATO],
            ['title' => 'Sopralluogo preventivo', 'days' => -2, 'hour' => 11, 'duration' => 1, 'status' => Appointment::STATUS_COMPLETATO],
            ['title' => 'Manutenzione ordinaria', 'days' => 1, 'hour' => 9, 'duration' => 2, 'status' => Appointment::STATUS_CONFERMATO],
            ['title' => 'Intervento in garanzia', 'days' => 3, 'hour' => 14, 'duration' => 1, 'status' => Appointment::STATUS_PIANIFICATO],
            ['title' => 'Formazione utilizzo macchina', 'days' => 7, 'hour' => 10, 'duration' => 1, 'status' => Appointment::STATUS_PIANIFICATO],
            ['title' => 'Manutenzione impianto birra', 'days' => 10, 'hour' => 8, 'duration' => 1, 'status' => Appointment::STATUS_PIANIFICATO],
        ];

        foreach ($plans as $i => $plan) {
            $startsAt = now()->addDays($plan['days'])->setTime($plan['hour'], 0);

            Appointment::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'title' => $plan['title'],
                    'starts_at' => $startsAt,
                ],
                [
                    'customer_id' => $customers[$i % $customers->count()]->id,
                    'technician_id' => $technicians[$i % $technicians->count()]->id,
                    'ends_at' => $startsAt->copy()->addHours($plan['duration']),
                    'status' => $plan['status'],
                ]
            );
        }
    }
}
