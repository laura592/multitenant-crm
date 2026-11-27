<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Via il campo note dai noleggi.
 *
 * Erano appunti interni -- "BOZZA DI STUDIO", "sconto fornitore ipotizzato",
 * "DA DEFINIRE: date della stagione" -- e sul contratto non sono mai
 * finiti. Laura li ha voluti via (27/11/2026).
 *
 * Le note di tutti e sette i noleggi sono state salvate fuori dal database
 * prima di cancellarle, in Documenti/Alex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            // Torna la colonna, non il contenuto: quello sta nel file
            // salvato in Documenti/Alex.
            $table->text('note')->nullable();
        });
    }
};
