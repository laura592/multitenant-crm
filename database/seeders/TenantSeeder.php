<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant master "Alex", usato come tenant di default dagli altri seeder demo
 * e dagli utenti di test creati da UserSeeder. Idempotente: firstOrCreate
 * per slug.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = config('tenant-defaults');

        $tenant = Tenant::firstOrCreate(
            ['slug' => $defaults['slug']],
            [
                'name' => $defaults['name'],
                'is_master' => true,
                'is_active' => true,
            ]
        );

        // Dati reali dell'azienda (da app_preventivi_vg/.env, mai stati
        // popolati qui): senza questi i PDF (preventivi, ordini materiali)
        // hanno un'intestazione vuota/spoglia - bug segnalato "manca logo e
        // intestazione societaria". Riempie solo i campi ancora vuoti: non
        // sovrascrive una correzione manuale gia' fatta da un admin.
        $tenant->fill([
            'legal_name' => $tenant->legal_name ?: $defaults['legal_name'],
            'vat_number' => $tenant->vat_number ?: $defaults['vat_number'],
            'tax_code' => $tenant->tax_code ?: $defaults['tax_code'],
            'sdi' => $tenant->sdi ?: $defaults['sdi'],
            'street' => $tenant->street ?: $defaults['street'],
            'postal_code' => $tenant->postal_code ?: $defaults['postal_code'],
            'city' => $tenant->city ?: $defaults['city'],
            'province' => $tenant->province ?: $defaults['province'],
            'phone' => $tenant->phone ?: $defaults['phone'],
            'fax' => $tenant->fax ?: $defaults['fax'],
            'email' => $tenant->email ?: $defaults['email'],
        ])->save();

        if (! $tenant->logo_path && file_exists(public_path('img/logo.png'))) {
            $path = 'tenant-logos/'.$tenant->id.'.png';
            Storage::disk('public')->put($path, file_get_contents(public_path('img/logo.png')));
            $tenant->update(['logo_path' => $path]);
        }
    }
}
