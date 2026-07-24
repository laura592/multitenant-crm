<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocoding via Nominatim (OpenStreetMap), gratuito e senza chiave API.
 * Usato per stimare le coordinate GPS di un cliente dal comune/CAP quando
 * non e' ancora disponibile una posizione precisa rilevata sul posto (vedi
 * ItalianAddressFields e CustomerResource, sezione "Posizione GPS").
 */
class Geocoder
{
    /**
     * @return array{lat: float, lng: float}|null
     */
    public static function geocode(string $address): ?array
    {
        return static::lookupAddress(static::normalizeAddress($address));
    }

    /**
     * @param array<int, string> $addresses
     * @return array{lat: float, lng: float}|null
     */
    public static function geocodeBestEffort(array $addresses): ?array
    {
        foreach (collect($addresses)->map(fn (string $address) => static::normalizeAddress($address))->filter()->unique() as $address) {
            $coords = static::lookupAddress($address);

            if ($coords) {
                return $coords;
            }
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected static function lookupAddress(string $address): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('services.nominatim.user_agent'),
            ])
                ->timeout(5)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'format' => 'json',
                    'q' => $address,
                    'countrycodes' => 'it',
                    'limit' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $result = $response->json('0');

            if (! $result) {
                return null;
            }

            return [
                'lat' => round((float) $result['lat'], 7),
                'lng' => round((float) $result['lon'], 7),
            ];
        } catch (\Throwable $e) {
            Log::warning('Geocoding OpenStreetMap fallito: '.$e->getMessage());

            return null;
        }
    }

    protected static function normalizeAddress(string $address): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($address)) ?? trim($address);
        $normalized = preg_replace('/\s*,\s*/u', ', ', $normalized) ?? $normalized;

        return trim($normalized, ', ');
    }
}
