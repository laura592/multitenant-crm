<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gestionale_suggested_code da solo (un id numerico) non basta per
 * giudicare a vista una proposta di collegamento nella pagina "Sync
 * Eureka" — serve anche la descrizione trovata su Eureka (ragione sociale
 * o codice+descrizione articolo), salvata al momento della proposta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('gestionale_suggested_label')->nullable()->after('gestionale_suggested_code');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('gestionale_suggested_label')->nullable()->after('gestionale_suggested_code');
        });

        Schema::table('machine_units', function (Blueprint $table) {
            $table->string('gestionale_suggested_label')->nullable()->after('gestionale_suggested_code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('gestionale_suggested_label');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('gestionale_suggested_label');
        });

        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn('gestionale_suggested_label');
        });
    }
};
