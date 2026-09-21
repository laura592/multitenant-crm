<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagina del preventivo per il cliente (QuoteClientController):
 * - il numero che il cliente chiama e' quello del commerciale, non il
 *   centralino dell'azienda;
 * - nessun indirizzo email in pagina: il cliente scrive col modulo, e chi
 *   riceve le risposte si decide in Impostazioni > Notifiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('client_contact_name')->nullable();
            $table->string('client_contact_phone', 50)->nullable();
            $table->json('notify_quote_response_emails')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['client_contact_name', 'client_contact_phone', 'notify_quote_response_emails']);
        });
    }
};
