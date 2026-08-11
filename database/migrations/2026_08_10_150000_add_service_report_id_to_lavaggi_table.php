<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            // Nullable: solo i lavaggi generati automaticamente da un rapportino
            // (ServiceReport::syncMaintenanceSchedule()) la valorizzano — quelli
            // inseriti a mano nel registro lavaggi restano NULL.
            $table->foreignUuid('service_report_id')->nullable()->after('machine_unit_id')
                ->constrained('service_reports')->cascadeOnDelete();

            $table->unique(['service_report_id', 'maintenance_schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            $table->dropUnique(['service_report_id', 'maintenance_schedule_id']);
            $table->dropConstrainedForeignId('service_report_id');
        });
    }
};
