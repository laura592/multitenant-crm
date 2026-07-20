<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'company_name',
        'street',
        'postal_code',
        'city',
        'province',
        'latitude',
        'longitude',
        'email',
        'mobile',
        'tax_code',
        'vat_number',
        'sdi',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function quoteGroups(): HasMany
    {
        return $this->hasMany(QuoteGroup::class);
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(InformationRequest::class);
    }

    /**
     * Ragione sociale prima di tutto (contesto B2B): se c'è anche un
     * referente, viene mostrato tra parentesi come informazione aggiuntiva,
     * non al posto della ragione sociale.
     */
    public function getFullNameAttribute(): string
    {
        $contact = trim("{$this->first_name} {$this->last_name}");

        if ($this->company_name && $contact) {
            return "{$this->company_name} ({$contact})";
        }

        return $this->company_name ?: $contact;
    }

    /**
     * Distanza in km dal punto indicato (formula haversine, raggio Terra
     * 6371 km). Null se il cliente non ha coordinate salvate.
     */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $earthRadiusKm = 6371;

        $latDelta = deg2rad((float) $this->latitude - $latitude);
        $lngDelta = deg2rad((float) $this->longitude - $longitude);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad((float) $this->latitude)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
