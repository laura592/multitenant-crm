<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            // Rilevante solo per type = lavaggio: birra/acqua/vino hanno regole
            // di scadenza diverse (vedi MaintenanceSchedule::recalculateLavaggioNextDue).
            $table->string('beverage_type')->nullable()->after('type');
            // Validita' standard del filtro per questo impianto acqua, in giorni.
            $table->unsignedSmallInteger('filter_validity_days')->nullable()->after('frequency_days');
            $table->foreignUuid('last_filter_change_id')->nullable()->after('last_lavaggio_id')
                ->constrained('lavaggi')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_filter_change_id');
            $table->dropColumn(['beverage_type', 'filter_validity_days']);
        });
    }
};
