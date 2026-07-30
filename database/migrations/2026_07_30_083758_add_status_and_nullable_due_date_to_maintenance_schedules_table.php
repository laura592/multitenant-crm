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
            // 'chiuso' = rapporto terminato (es. cliente non più servito):
            // resta nello storico ma nascosto dalla vista di default.
            $table->enum('status', ['attivo', 'chiuso'])->default('attivo')->after('type');
            // I piani "a chiamata" (senza cadenza fissa, es. lavaggi su
            // richiesta) non hanno una prossima scadenza calcolabile.
            $table->date('next_due_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->date('next_due_date')->nullable(false)->change();
        });
    }
};
