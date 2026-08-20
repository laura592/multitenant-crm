<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            // Categoria del macchinario per gli impianti bevande (colonna
            // spina birra/vino/selz vs impianto acqua standalone): non va
            // confusa con MaintenanceSchedule::beverage_type, che resta per
            // ogni singolo piano/scadenza collegato alla stessa colonna (una
            // colonna puo' avere piu' piani, uno per tipo bevanda erogata).
            // Nullo per i macchinari che non sono impianti bevande (macchine
            // da caffe', macinadosatori, ecc.).
            $table->string('type')->nullable()->after('model_name');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
