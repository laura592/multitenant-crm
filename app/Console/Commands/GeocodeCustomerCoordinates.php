<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Support\Geocoder;
use Illuminate\Console\Command;

class GeocodeCustomerCoordinates extends Command
{
    protected $signature = 'customers:geocode-coordinates
        {--overwrite : Ricalcola anche i clienti che hanno gia coordinate}
        {--tenant= : Limita il backfill a uno specifico slug tenant}';

    protected $description = 'Completa o ricalcola le coordinate cliente partendo dall\'indirizzo';

    public function handle(): int
    {
        $query = Customer::query();

        if ($tenantSlug = $this->option('tenant')) {
            $query->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('slug', $tenantSlug));
        }

        if (! $this->option('overwrite')) {
            $query->where(function ($inner) {
                $inner->whereNull('latitude')->orWhereNull('longitude');
            });
        }

        $customers = $query->orderBy('company_name')->get();

        if ($customers->isEmpty()) {
            $this->info('Nessun cliente da geocodificare.');

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        $this->output->progressStart($customers->count());

        foreach ($customers as $customer) {
            $address = $customer->geocodingAddress();

            if (blank($address) || (blank($customer->city) && blank($customer->postal_code))) {
                $skipped++;
                $this->output->progressAdvance();

                continue;
            }

            $coords = Geocoder::geocodeBestEffort($customer->geocodingAddressCandidates());

            if (! $coords) {
                $failed++;
                $this->warn("Geocoding fallito per {$customer->full_name} [{$address}]");
                $this->output->progressAdvance();

                continue;
            }

            $customer->forceFill([
                'latitude' => $coords['lat'],
                'longitude' => $coords['lng'],
            ])->save();

            $updated++;
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->info("Coordinate aggiornate: {$updated}");
        $this->line("Indirizzi incompleti saltati: {$skipped}");
        $this->line("Geocoding falliti da rivedere: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}