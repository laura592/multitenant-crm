<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

class NormalizeCustomerMobileNumbers extends Command
{
    protected $signature = 'customers:normalize-mobile-numbers';

    protected $description = 'Riscrive i numeri di cellulare/telefono dei clienti nel formato +39 (usa il mutator del modello)';

    public function handle(): int
    {
        $customers = Customer::query()
            ->whereNotNull('phones')
            ->where('phones', '!=', '[]')
            ->get();

        $updated = 0;

        foreach ($customers as $customer) {
            $customer->phones = $customer->phones;

            if (! $customer->isDirty('phones')) {
                continue;
            }

            $customer->save();
            $updated++;
        }

        $this->info("Numeri normalizzati: {$updated}");

        return self::SUCCESS;
    }
}
