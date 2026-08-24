<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            // id_intestatario_fattura_f15 di Eureka (GET /show/q/art_installati,
            // confermato dal vendor via email 2026-08-24): l'anagrafica che paga
            // davvero gli interventi su QUESTA macchina, se diverso dal cliente
            // presso cui e' installata. Fonte piu' diretta e sempre disponibile
            // (per macchina, non serve un rapportino gia' importato) di
            // ServiceReport.eureka_destinazione_code usato finora — vedi
            // eureka:apply-machine-billing-payer per come si traduce in
            // billing_customer_id.
            $table->unsignedInteger('eureka_billing_customer_code')->nullable()->after('gestionale_suggested_label');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn('eureka_billing_customer_code');
        });
    }
};
