<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue i materiali inseriti a mano da quelli importati da Eureka
 * (ricambi usati nei rapportini, vedi ServiceReportMaterial) — stesso
 * pattern di Product::SOURCE_FRANKE/SOURCE_THIRD_PARTY.
 * gestionale_code serve a ricostruire dettaglio[].id_articolo nel payload
 * inviato a Eureka (ServiceReport::toGestionalePayload()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('source')->default('manuale')->after('tenant_id');
            $table->unsignedInteger('gestionale_code')->nullable()->after('code');
            $table->index('gestionale_code');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['gestionale_code']);
            $table->dropColumn(['source', 'gestionale_code']);
        });
    }
};
