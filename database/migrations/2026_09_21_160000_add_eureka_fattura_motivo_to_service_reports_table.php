<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perche' un rapportino su Eureka non ha una fattura collegata, e la prova.
 *
 * Nasce dai 150 rapportini "senza fattura" del 21/09/2026: l'ufficio li ha
 * trovati troppi, e aveva ragione. Guardati uno per uno erano doppioni di
 * schede fatturate, schede senza importo, fatture fatte a mano senza partire
 * dalla scheda — e solo 17 da verificare davvero. Qui il CRM lo fa da solo:
 * vedi App\Support\Gestionale\SenzaFatturaCollegata.
 *
 * - eureka_fattura_motivo: recente | senza_importo | doppione |
 *   fattura_non_collegata | da_verificare. NULL sui fatturati.
 * - eureka_fattura_indizio: la prova, da leggere in colonna ("RT-2025-0892",
 *   "FT 479 del 07/11/2025").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->string('eureka_fattura_motivo', 30)->nullable()->after('eureka_fatture_controllate_il');
            $table->string('eureka_fattura_indizio')->nullable()->after('eureka_fattura_motivo');
            $table->index(['tenant_id', 'eureka_fattura_motivo']);
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'eureka_fattura_motivo']);
            $table->dropColumn(['eureka_fattura_motivo', 'eureka_fattura_indizio']);
        });
    }
};
