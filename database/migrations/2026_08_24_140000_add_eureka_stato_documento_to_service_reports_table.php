<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // Valore grezzo dello "stato mobile" Eureka (scala fissa 0-10,
            // confermata dal vendor via email 2026-08-24: 10=Chiuso, 7=Finita,
            // 6=Evaso parzialmente, 3=nessuno, 2=Inviata ai tablet, 1=Nuova).
            // ImportEurekaServiceReports::mapStatus() gia' legge
            // stato_documento ma lo riduce subito a bozza/inviato (=== 10):
            // qui si tiene anche il valore/etichetta originali, per quando
            // servira' una logica di invio piu' fine (non ancora progettata,
            // vedi memoria progetto — nessun comportamento esistente cambia).
            $table->unsignedTinyInteger('eureka_stato_documento')->nullable()->after('eureka_destinazione_label');
            $table->string('eureka_stato_label')->nullable()->after('eureka_stato_documento');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn(['eureka_stato_documento', 'eureka_stato_label']);
        });
    }
};
