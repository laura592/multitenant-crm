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

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Clienti vicini';

    protected static string $view = 'filament.pages.clienti-vicini';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public function getNearbyCustomers(): Collection
    {
        if ($this->latitude === null || $this->longitude === null) {
            return collect();
        }

        $tenant = Filament::getTenant();

        return Customer::query()
            ->where('tenant_id', $tenant?->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(fn (Customer $customer) => [
                'customer' => $customer,
                'distance' => $customer->distanceFrom($this->latitude, $this->longitude),
            ])
            ->sortBy('distance')
            ->take(25)
            ->values();
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
