<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Support\Geocoder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Geocodifica un cliente appena creato fuori dal ciclo che l'ha creato (es.
 * ImportEurekaServiceReports): era una usleep(1.1s) sincrona per ogni
 * cliente nuovo (rate-limit di Nominatim, max 1 richiesta/secondo), che
 * sommata su un import che crea molti clienti aggiungeva minuti interi al
 * comando. La pausa resta qui (stessa policy Nominatim), ma non blocca piu'
 * chi ha lanciato il comando — solo il worker della coda.
 */
class GeocodeCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly Customer $customer) {}

    public function handle(): void
    {
        $customer = $this->customer->fresh();

        if (! $customer || blank($customer->city) && blank($customer->postal_code)) {
            return;
        }

        if ($customer->latitude !== null && $customer->longitude !== null) {
            return;
        }

        $coords = Geocoder::geocodeBestEffort($customer->geocodingAddressCandidates());
        usleep(1_100_000);

        if (! $coords) {
            return;
        }

        $customer->forceFill([
            'latitude' => $coords['lat'],
            'longitude' => $coords['lng'],
        ])->save();
    }
}
