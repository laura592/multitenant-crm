<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

/**
 * I 4 brand del catalogo (files/PRD.md §3.1): Franke/Dalla Corte/Jura sono i
 * marchi commerciali veri, "Universale/Accessori" copre i componenti
 * cross-brand a cui ogni partner ha sempre accesso oltre ai brand assegnati.
 * Idempotente: firstOrCreate per nome.
 */
class BrandSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Franke', 'Dalla Corte', 'Jura', 'Universale/Accessori'] as $name) {
            Brand::firstOrCreate(['name' => $name]);
        }
    }
}
