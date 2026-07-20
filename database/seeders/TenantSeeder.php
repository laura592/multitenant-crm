<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Tenant master "Alex", usato come tenant di default dagli altri seeder demo
 * e dagli utenti di test creati da UserSeeder. Idempotente: firstOrCreate
 * per slug.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(
            ['slug' => 'alex'],
            [
                'name' => 'Alex',
                'is_master' => true,
                'is_active' => true,
            ]
        );
    }
}
