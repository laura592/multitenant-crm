<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Unico fornitore del catalogo materiali seed (vedi MaterialSeeder, listino
 * John Guest). Condiviso fra tutti i tenant (tenant_id NULL, stesso pattern
 * di BrandSeeder/MaterialSeeder). Idempotente: firstOrCreate per nome.
 */
class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::firstOrCreate(['name' => 'John Guest']);
    }
}
