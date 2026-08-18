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
        Schema::create('service_report_maintenance_schedule', function (Blueprint $table) {
            $table->foreignUuid('service_report_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('maintenance_schedule_id')->constrained()->cascadeOnDelete();
            $table->primary(['service_report_id', 'maintenance_schedule_id']);
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
