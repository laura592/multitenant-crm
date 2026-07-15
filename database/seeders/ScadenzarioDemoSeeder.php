<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Deadline;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * Popola lo scadenzario (docs/architecture.md §13) con le scadenze aziendali
 * tipiche di un distributore/installatore come Alex, e un piano di
 * manutenzione di esempio su clienti reali gia' importati. Idempotente
 * (updateOrCreate), pensato per il tenant master gia' presente nel DB.
 */
class ScadenzarioDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('is_master', true)->first();

        if (! $tenant) {
            $this->command->error('Nessun tenant master trovato: esegui prima l\'import dei dati.');

            return;
        }

        $this->seedVehicles($tenant);
        $this->seedCompanyDeadlines($tenant);
        $this->seedMaintenanceSchedules($tenant);

        $this->command->info('Scadenzario demo popolato.');
    }

    protected function seedVehicles(Tenant $tenant): void
    {
        $vehicles = [
            ['plate' => 'FG123AB', 'brand' => 'Fiat', 'model' => 'Ducato', 'year' => 2022, 'insurance_months' => 2, 'revisione_months' => 8],
            ['plate' => 'FG456CD', 'brand' => 'Fiat', 'model' => 'Doblò', 'year' => 2023, 'insurance_months' => 6, 'revisione_months' => 18],
            ['plate' => 'FG789EF', 'brand' => 'Volkswagen', 'model' => 'Transporter', 'year' => 2021, 'insurance_months' => 1, 'revisione_months' => 4],
        ];

        foreach ($vehicles as $data) {
            $vehicle = Vehicle::updateOrCreate(
                ['tenant_id' => $tenant->id, 'plate' => $data['plate']],
                ['brand' => $data['brand'], 'model' => $data['model'], 'year' => $data['year']]
            );

            $vehicle->deadlines()->updateOrCreate(
                ['type' => Deadline::TYPE_ASSICURAZIONE],
                ['tenant_id' => $tenant->id, 'due_date' => now()->addMonths($data['insurance_months']), 'reminder_days_before' => 30, 'status' => 'attiva']
            );

            $vehicle->deadlines()->updateOrCreate(
                ['type' => Deadline::TYPE_REVISIONE],
                ['tenant_id' => $tenant->id, 'due_date' => now()->addMonths($data['revisione_months']), 'reminder_days_before' => 60, 'status' => 'attiva']
            );
        }
    }

    protected function seedCompanyDeadlines(Tenant $tenant): void
    {
        // Polizza RCT (art. 17 del contratto tipo di distribuzione: obbligatoria
        // per gli scenari in cui l'installazione e' eseguita in autonomia)
        $tenant->deadlines()->updateOrCreate(
            ['type' => Deadline::TYPE_POLIZZA_RCT],
            ['tenant_id' => $tenant->id, 'due_date' => now()->addMonths(9), 'reminder_days_before' => 45, 'status' => 'attiva', 'notes' => 'Responsabilità civile verso terzi per installazioni in autonomia (art. 17 contratto distribuzione).']
        );

        // Contratti generici di gestione azienda
        $contracts = [
            ['notes' => 'Contratto di locazione sede operativa', 'months' => 14],
            ['notes' => 'Contratto commercialista/consulenza fiscale', 'months' => 5],
            ['notes' => 'Contratto assistenza software gestionale', 'months' => 3],
        ];

        foreach ($contracts as $i => $c) {
            $tenant->deadlines()->updateOrCreate(
                ['type' => Deadline::TYPE_CONTRATTO, 'notes' => $c['notes']],
                ['tenant_id' => $tenant->id, 'due_date' => now()->addMonths($c['months']), 'reminder_days_before' => 60, 'status' => 'attiva']
            );
        }

        // Adempimenti/certificazioni ricorrenti tipici di una PMI italiana
        $compliance = [
            ['notes' => 'DURC - Documento Unico di Regolarità Contributiva', 'months' => 1, 'reminder' => 15],
            ['notes' => 'Assicurazione RC aziendale generale', 'months' => 7, 'reminder' => 30],
            ['notes' => 'Polizza infortuni dipendenti', 'months' => 8, 'reminder' => 30],
            ['notes' => 'Verifica/manutenzione estintori sede', 'months' => 4, 'reminder' => 15],
            ['notes' => 'Verifica impianto elettrico e messa a terra', 'months' => 11, 'reminder' => 30],
            ['notes' => 'Revisione periodica muletto/carrello elevatore magazzino', 'months' => 6, 'reminder' => 30],
        ];

        foreach ($compliance as $c) {
            $tenant->deadlines()->updateOrCreate(
                ['type' => Deadline::TYPE_ALTRO, 'notes' => $c['notes']],
                ['tenant_id' => $tenant->id, 'due_date' => now()->addMonths($c['months']), 'reminder_days_before' => $c['reminder'], 'status' => 'attiva']
            );
        }
    }

    protected function seedMaintenanceSchedules(Tenant $tenant): void
    {
        $plans = [
            ['customer' => 'Hotel Universal', 'frequency' => 'trimestrale', 'due_in_days' => 12],
            ['customer' => 'Hotel Tintoretto', 'frequency' => 'semestrale', 'due_in_days' => 45],
            ['customer' => 'Italian Hotel Group', 'frequency' => 'mensile', 'due_in_days' => -5], // scaduta, per mostrare il caso "in ritardo"
            ['customer' => 'Active Hotel Malita', 'frequency' => 'annuale', 'due_in_days' => 200],
            ['customer' => 'Gelateria Mozart', 'frequency' => 'trimestrale', 'due_in_days' => 25],
        ];

        foreach ($plans as $plan) {
            $customer = Customer::where('tenant_id', $tenant->id)
                ->where('company_name', $plan['customer'])
                ->first();

            if (! $customer) {
                continue;
            }

            MaintenanceSchedule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'customer_id' => $customer->id],
                [
                    'frequency' => $plan['frequency'],
                    'next_due_date' => now()->addDays($plan['due_in_days']),
                ]
            );
        }
    }
}
