<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // Numero assegnato da Eureka ("SL-{numero}/{anno}"), tenuto
            // separato da "number": prima dell'introduzione di questa colonna
            // SendServiceReportToGestionaleJob sovrascriveva "number" al
            // momento dell'invio, cambiandolo da RT-... a SL-... — il cliente
            // riceveva pero' gia' il PDF/email con il numero RT-... prima
            // dell'invio, quindi il CRM finiva per mostrare un numero diverso
            // da quello consegnato. "number" ora resta stabile dalla
            // creazione, questo campo tiene il riferimento verso il
            // gestionale quando esiste.
            $table->string('gestionale_number')->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn('gestionale_number');
        });
    }
};
