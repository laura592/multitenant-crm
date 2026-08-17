<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // "destinazione" di Eureka (doc API §6.1): chi paga davvero per
            // questo intervento, se diverso dall'intestatario/luogo. Mai letto
            // da ImportEurekaServiceReports finora, quindi mai mostrato in CRM
            // — trovato dal vivo confrontando le schede lavoro reali (audit
            // 2026-08-13, es. Hotel Margherita di Tiepolo Susanna pagata da
            // Martellozzo Lorenzo & C. SAS). eureka_destinazione_label tiene
            // il testo Eureka anche quando non esiste (ancora) un Customer
            // locale con quel gestionale_code corrispondente.
            $table->unsignedInteger('eureka_destinazione_code')->nullable()->after('eureka_service_report_id');
            $table->string('eureka_destinazione_label')->nullable()->after('eureka_destinazione_code');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn(['eureka_destinazione_code', 'eureka_destinazione_label']);
        });
    }
};
