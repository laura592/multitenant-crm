<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controllo del pagante dei rapportini nel gestionale (22/09/2026).
 *
 * Il pagante nel CRM e' quello della scheda Eureka, copiato e mai deciso qui.
 * La fattura serve solo da controllo: quando e' intestata a un altro, la
 * scheda va corretta su Eureka. Qui si segna la differenza, per l'elenco
 * "Schede da correggere su Eureka" in Verifica sync gestionale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // A chi e' intestata la fattura, quando non e' il pagante della scheda.
            $table->foreignUuid('pagante_fattura_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('pagante_fattura_rilevato_il')->nullable();
            // "Va bene cosi'": questa differenza non si segnala piu'.
            // Chiave pagante scheda|intestatario fattura: se una delle due
            // cambia, la differenza nuova si segnala.
            $table->string('pagante_fattura_ok', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pagante_fattura_customer_id');
            $table->dropColumn(['pagante_fattura_rilevato_il', 'pagante_fattura_ok']);
        });
    }
};
