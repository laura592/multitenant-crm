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
            MaterialSeeder::class,
            TenantSeeder::class,
            UserSeeder::class,
            RolesAndPermissionsSeeder::class,
            ScadenzarioDemoSeeder::class,
            BeerLineMaintenanceDemoSeeder::class,
            DemoOperationalDataSeeder::class,
            AppointmentSeeder::class,
        ]);
    }
}
