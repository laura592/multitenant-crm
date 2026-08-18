<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Selezione esplicita di quali piani lavaggio copre un rapportino di
        // sanificazione multi-impianto (ServiceReport::syncMaintenanceSchedule()):
        // quando presente, sostituisce la regola implicita "tutti i piani
        // attivi del cliente" / "solo il piano della machine_unit_id scelta".
        // Pura tabella pivot, senza tenant_id: entrambe le FK puntano gia' a
        // record dello stesso tenant.
        // Nomi vincolo espliciti e corti: quello di default
        // ("service_report_maintenance_schedule_maintenance_schedule_id_foreign",
        // 67 caratteri) supera il limite di 64 di MySQL — fallisce solo su
        // MySQL/MariaDB, non sullo sqlite dei test, quindi la suite non lo
        // becca.
        //
        // dropIfExists difensivo: la primissima versione di questa migrazione
        // (senza nomi vincolo corti) falliva a meta', creando la tabella ma
        // non i suoi indici — su ambienti dove e' girata prima di questo fix
        // la CREATE TABLE sotto fallirebbe con "table already exists".
        // Questa migrazione non e' mai arrivata a completarsi con successo da
        // nessuna parte, quindi non c'e' dato reale da perdere ripartendo da
        // zero.
        Schema::dropIfExists('service_report_maintenance_schedule');

        Schema::create('service_report_maintenance_schedule', function (Blueprint $table) {
            $table->uuid('service_report_id');
            $table->uuid('maintenance_schedule_id');
            $table->primary(['service_report_id', 'maintenance_schedule_id']);

            $table->foreign('service_report_id', 'srms_service_report_fk')
                ->references('id')->on('service_reports')->cascadeOnDelete();

            $table->foreign('maintenance_schedule_id', 'srms_maintenance_schedule_fk')
                ->references('id')->on('maintenance_schedules')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_report_maintenance_schedule');
    }
};
