<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Clienti di prova con impianto birra alla spina, per il piano di
 * manutenzione "lavaggio impianto birra" (frequenza mensile, tipico per
 * normativa/best practice sugli impianti di spillatura). Idempotente
 * (updateOrCreate).
 */
class BeerLineMaintenanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('is_master', true)->first();

        if (! $tenant) {
            $this->command->error('Nessun tenant master trovato: esegui prima l\'import dei dati.');

            return;
        }

        $pubs = [
            ['name' => 'Birreria Old Wild', 'city' => 'Treviso', 'province' => 'TV', 'due_in_days' => 5],
            ['name' => 'Pub The Golden Tap', 'city' => 'Venezia', 'province' => 'VE', 'due_in_days' => -3], // in ritardo
            ['name' => 'Osteria alla Spina', 'city' => 'Padova', 'province' => 'PD', 'due_in_days' => 18],
            ['name' => 'Craft Beer Corner', 'city' => 'Vicenza', 'province' => 'VI', 'due_in_days' => 25],
            ['name' => 'Birrificio Rifugio', 'city' => 'Belluno', 'province' => 'BL', 'due_in_days' => 10],
        ];

        foreach ($pubs as $pub) {
            $customer = Customer::updateOrCreate(
                ['tenant_id' => $tenant->id, 'company_name' => $pub['name']],
                ['city' => $pub['city'], 'province' => $pub['province']]
            );

            MaintenanceSchedule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'notes' => 'Lavaggio impianto birra'],
                [
                    'frequency' => 'mensile',
                    'next_due_date' => now()->addDays($pub['due_in_days']),
                ]
            );
        }

        $this->command->info('Clienti e piani di lavaggio impianto birra popolati.');
    }
}
