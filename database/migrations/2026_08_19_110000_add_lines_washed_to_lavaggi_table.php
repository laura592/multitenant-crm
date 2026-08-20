<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            // Conteggio strutturato delle vie lavate in questa visita (es. 3
            // vie birra), da affiancare a "descrizione" che resta testo
            // libero per note accessorie ("apertura", "chiusura stagionale").
            // Puo' differire da MaintenanceSchedule::lines_count (le vie
            // previste sull'impianto) se quella volta non sono state lavate
            // tutte.
            $table->unsignedSmallInteger('lines_washed')->nullable()->after('descrizione');
        });
    }

    public function down(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            $table->dropColumn('lines_washed');
        });
    }
};
