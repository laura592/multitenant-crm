<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La programmazione tiene anche l'ora, e i richiami (04/11/2026).
 *
 * Mancavano due cose, e per via di quelle i richiami ai clienti stavano
 * scritti nel testo libero delle richieste informazioni — dove nessuno li
 * vede. Su RI-2026-0215 (Hotel Alle Alpi) c'era "CHIAMARE A INIZIO
 * NOVEMBRE" in mezzo al resto, e infatti è passato.
 *
 * `momento` sapeva dire mattina, pomeriggio o giornata: "giovedì alle 9:30"
 * non si poteva scrivere. L'ora è facoltativa — la maggior parte degli
 * interventi una mattina o un pomeriggio, e basta — ma quando c'è un
 * appuntamento preso, va scritta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventi_programmati', function (Blueprint $table) {
            $table->time('ora')->nullable()->after('momento');
        });
    }

    public function down(): void
    {
        Schema::table('interventi_programmati', function (Blueprint $table) {
            $table->dropColumn('ora');
        });
    }
};
