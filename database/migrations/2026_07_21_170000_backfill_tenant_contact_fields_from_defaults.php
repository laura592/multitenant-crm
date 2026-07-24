<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Riempie solo i campi ancora vuoti del tenant master con i dati reali
     * dell'azienda (config('tenant-defaults')): senza questi i PDF (preventivi,
     * ordini materiali) avevano un'intestazione vuota/spoglia. Non sovrascrive
     * una correzione manuale gia' fatta da un admin. Vedi anche
     * Database\Seeders\TenantSeeder, che applica la stessa logica ai tenant
     * creati da zero.
     */
    public function up(): void
    {
        $defaults = config('tenant-defaults');

        $tenant = Tenant::where('slug', $defaults['slug'])->first();

        if (! $tenant) {
            return;
        }

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
    }

    public function down(): void
    {
        // Backfill dati: nessuna azione di ripristino sensata.
    }
};
