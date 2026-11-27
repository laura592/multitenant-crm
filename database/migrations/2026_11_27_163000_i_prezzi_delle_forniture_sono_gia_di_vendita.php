<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Via il ricarico riga per riga dalle forniture dei noleggi.
 *
 * Il prezzo che si scrive sulla riga e' gia' quello di vendita (Laura,
 * 27/11/2026). Il ricarico moltiplicava il costo mensile per (1 + x/100),
 * ed era 0,00 su tutte e 40 le righe a sistema: moltiplicava per uno.
 *
 * Togliendolo il canone non cambia di un centesimo -- e' stato verificato
 * sui dati prima di toccare le formule -- ma sparisce una casella che
 * chiedeva un numero che nessuno vuole dare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            $table->dropColumn('ricarico');
        });
    }

    public function down(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            $table->decimal('ricarico', 5, 2)->default(0)->after('prezzo_acquisto');
        });
    }
};
