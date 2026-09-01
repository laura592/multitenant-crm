<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Il digest dei lavaggi scaduti/in scadenza nasce da una richiesta precisa
     * ("mandalo a s.alessandro"), quindi la lista parte gia' compilata: senza
     * questo passo il comando schedulato girerebbe ogni lunedi' senza
     * destinatari e non manderebbe niente, in silenzio, finche' qualcuno non
     * apre Impostazioni > Notifiche. Solo se la lista e' ancora vuota, cosi'
     * una modifica fatta a mano dal pannello non viene sovrascritta.
     */
    public function up(): void
    {
        $tenant = Tenant::where('slug', config('tenant-defaults.slug'))->first();

        if (! $tenant || ! empty($tenant->notify_lavaggio_emails)) {
            return;
        }

        $tenant->update(['notify_lavaggio_emails' => ['s.alessandro@alexcaffe.com']]);
    }

    public function down(): void
    {
        // Backfill dati: nessuna azione di ripristino sensata.
    }
};
