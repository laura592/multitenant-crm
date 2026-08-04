<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            // Questa visita ha incluso la sostituzione del filtro (impianti acqua):
            // usato per calcolare la scadenza del piano di lavaggio collegato.
            $table->boolean('filtro_sostituito')->default(false)->after('descrizione');
        });
    }

    public function down(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            $table->dropColumn('filtro_sostituito');
        });
    }
};
