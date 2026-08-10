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
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            // Numero di "vie" (rubinetti/linee) collegate a questo piano di
            // lavaggio, es. "8 vie vino". Non rilevante per beverage_type =
            // acqua (la casetta acqua non ha un conteggio di vie).
            $table->unsignedSmallInteger('lines_count')->nullable()->after('beverage_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropColumn('lines_count');
        });
    }
};
