<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ServiceReportResource;
use App\Models\Customer;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Elenco clienti piu' vicini alla posizione GPS corrente (rilevata dal
 * browser), per individuare rapidamente presso quale cliente ci si trova o
 * quali sono nei paraggi durante un giro visite. Distanza calcolata in PHP
 * (haversine, Customer::distanceFrom) sul set di clienti con coordinate
 * salvate: volumi da poche centinaia di righe, non serve spingere il calcolo
 * a livello SQL.
 */
class ClientiVicini extends Page
{
    use HasPageShield;

    protected const MAX_RESULTS = 25;

    public const FALLBACK_RESULTS = 10;

    protected const DEFAULT_MAX_DISTANCE_KM = 5.0;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Clienti vicini';

    protected static ?string $title = 'Clienti vicini';

    protected static string $view = 'filament.pages.clienti-vicini';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public float $maxDistanceKm = self::DEFAULT_MAX_DISTANCE_KM;

    /**
     * Memo per request: la view chiama nearbyCustomerMarkers(),
     * getNearbyCustomers() e isUsingDistanceFallback() (due volte) nello
     * stesso render, e senza cache ognuna rieseguiva la query + calcolo
     * haversine su tutti i clienti da capo (4 esecuzioni per caricamento
     * pagina). Proprieta' protected: Livewire non le persiste tra una
     * richiesta e l'altra, quindi restano valide solo per il render corrente.
     */
    protected ?Collection $cachedNearbyRows = null;

    protected ?bool $cachedUsingFallback = null;

    public function getSubheading(): ?string
    {
        return 'Aggiorna la posizione e usa la lista ordinata per distanza per aprire subito rapportino o Maps.';
    }

    public function maxResults(): int
    {
        return self::MAX_RESULTS;
    }

    protected function nearbyCustomerRows(): Collection
    {
        if ($this->cachedNearbyRows !== null) {
            return $this->cachedNearbyRows;
        }

        $tenant = Filament::getTenant();

        $rows = Customer::query()
            ->where('tenant_id', $tenant?->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(fn (Customer $customer) => [
                'customer' => $customer,
                'distance' => $customer->distanceFrom($this->latitude, $this->longitude),
            ])
            ->filter(fn (array $row) => $row['distance'] !== null)
            ->sortBy('distance')
            ->values();

        $withinRadius = $rows
            ->filter(fn (array $row) => $row['distance'] <= $this->maxDistanceKm)
            ->take(self::MAX_RESULTS)
            ->values();

        $this->cachedUsingFallback = $withinRadius->isEmpty() && $rows->isNotEmpty();

        return $this->cachedNearbyRows = $withinRadius->isNotEmpty()
            ? $withinRadius
            : $rows->take(self::FALLBACK_RESULTS)->values();
    }

    public function getNearbyCustomers(): Collection
    {
        if ($this->latitude === null || $this->longitude === null) {
            return collect();
        }

        return $this->nearbyCustomerRows();
    }

    public function nearbyCustomerMarkers(): array
    {
        return $this->getNearbyCustomers()
            ->map(fn (array $row) => [
                'name' => $row['customer']->full_name,
                'street' => $row['customer']->street,
                'city' => $row['customer']->city,
                'lat' => (float) $row['customer']->latitude,
                'lng' => (float) $row['customer']->longitude,
                'distance' => round((float) $row['distance'], 1),
                'serviceReportUrl' => $this->serviceReportUrlFor($row['customer']),
                'mapsUrl' => $this->mapsUrlFor($row['customer']),
            ])
            ->values()
            ->all();
    }

    public function mapEmbedUrl(): string
    {
        if ($this->latitude === null || $this->longitude === null) {
            return '';
        }

        $markers = $this->nearbyCustomerMarkers();

        $points = [[(float) $this->latitude, (float) $this->longitude]];

        foreach ($markers as $marker) {
            $points[] = [(float) $marker['lat'], (float) $marker['lng']];
        }

        $latitudes = array_column($points, 0);
        $longitudes = array_column($points, 1);

        $bbox = implode(',', [
            min($longitudes),
            min($latitudes),
            max($longitudes),
            max($latitudes),
        ]);

        return 'https://www.openstreetmap.org/export/embed.html?bbox='
            .rawurlencode($bbox)
            .'&layer=mapnik&marker='
            .rawurlencode($this->latitude.','.$this->longitude);
    }

    public function defaultMaxDistanceKm(): float
    {
        return self::DEFAULT_MAX_DISTANCE_KM;
    }

    public function isUsingDistanceFallback(): bool
    {
        if ($this->latitude === null || $this->longitude === null) {
            return false;
        }

        $this->nearbyCustomerRows();

        return $this->cachedUsingFallback ?? false;
    }

    public function serviceReportUrlFor(Customer $customer): string
    {
        return ServiceReportResource::getUrl('create', ['customer_id' => $customer->id]);
    }

    public function mapsUrlFor(Customer $customer): string
    {
        return "https://www.google.com/maps/search/?api=1&query={$customer->latitude},{$customer->longitude}";
    }
}
