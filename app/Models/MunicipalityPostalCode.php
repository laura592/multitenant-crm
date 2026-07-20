<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dato di riferimento (comune/provincia/CAP italiano), sola lettura: alimenta
 * l'autocomplete indirizzo di CustomerResource. Vedi MunicipalityPostalCodeSeeder.
 */
class MunicipalityPostalCode extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'municipality_name',
        'province_name',
        'province_code',
        'postal_code',
    ];

    public function getLabelAttribute(): string
    {
        return "{$this->municipality_name} ({$this->province_code}) - {$this->postal_code}";
    }
}
