<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // Eureka ha due date distinte su una scheda lavoro: "data" (data
            // del documento, spesso quella in cui e' stato archiviato/chiuso
            // in ufficio) e "sl_dataora_appuntamento" (quando il tecnico e'
            // stato davvero dal cliente, a volte giorni prima). L'import
            // finora mappava "data" su intervention_date, quindi il CRM
            // mostrava la data del documento spacciandola per data
            // dell'intervento. Questa colonna tiene la data documento a
            // parte cosi' intervention_date puo' tornare a essere la data
            // vera dell'intervento (sl_dataora_appuntamento).
            $table->date('gestionale_document_date')->nullable()->after('gestionale_number');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn('gestionale_document_date');
        });
    }
};
