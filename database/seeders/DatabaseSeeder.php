<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            MunicipalityPostalCodeSeeder::class,
            BrandSeeder::class,
            SupplierSeeder::class,
            MaterialSeeder::class,
            TenantSeeder::class,
            UserSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);

        // I seeder di dati finti (scadenzario, lavaggi birra, dati operativi)
        // sono stati tolti il 02/09/2026: il locale si popola dal dump di
        // produzione, e clienti/rapportini inventati in mezzo a quelli veri
        // costavano piu' fatica di quanta ne risparmiassero. Quelli rimasti
        // caricano anagrafiche reali (comuni, marchi, fornitori, materiali) o
        // servono a far partire l'app (tenant, utenti, ruoli).
    }
}
