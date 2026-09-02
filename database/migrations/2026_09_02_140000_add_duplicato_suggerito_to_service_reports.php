<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // Proposta di abbinamento fra un rapportino compilato nel CRM e
            // la scheda lavoro importata da Eureka che documenta lo STESSO
            // intervento.
            //
            // Nasce da un problema reale: l'ufficio inserisce in Eureka
            // interventi che il tecnico ha gia' registrato qui, e dopo un
            // import il CRM si ritrova lo stesso lavoro due volte. Al
            // 02/09/2026 erano 52 rapportini su 57.
            //
            // Come per i collegamenti di clienti e macchinari, qui si
            // PROPONE soltanto: unire due rapportini e' irreversibile e
            // tocca dati firmati dal cliente, quindi la conferma resta a una
            // persona (vedi GestionaleDoppioniRapportiniWidget).
            $table->foreignUuid('duplicato_suggerito_id')->nullable()
                ->after('eureka_service_report_id')
                ->constrained('service_reports')->nullOnDelete();

            // Perche' il sistema li ritiene lo stesso intervento: serve a chi
            // conferma per capire quanto fidarsi senza riaprire entrambi.
            $table->string('duplicato_suggerito_motivo')->nullable()
                ->after('duplicato_suggerito_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicato_suggerito_id');
            $table->dropColumn('duplicato_suggerito_motivo');
        });
    }
};
