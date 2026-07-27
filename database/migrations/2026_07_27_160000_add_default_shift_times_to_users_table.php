<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Orario standard per dipendente (es. 8-12/13-17): usato per
            // pre-compilare il form Presenze e per il pulsante "Turno
            // standard di oggi" (docs/architecture.md §12). Nullable: senza
            // configurazione l'orario resta da inserire a mano come prima.
            $table->time('default_morning_in')->nullable()->after('annual_leave_days');
            $table->time('default_morning_out')->nullable()->after('default_morning_in');
            $table->time('default_afternoon_in')->nullable()->after('default_morning_out');
            $table->time('default_afternoon_out')->nullable()->after('default_afternoon_in');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'default_morning_in',
                'default_morning_out',
                'default_afternoon_in',
                'default_afternoon_out',
            ]);
        });
    }
};
