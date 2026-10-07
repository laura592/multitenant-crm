<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il noleggio ammortizza il LISTINO, non il costo d'acquisto (07/10/2026).
 *
 * Ammortizzare quello che la macchina e' costata ad Alex significa, nella
 * migliore delle ipotesi, rientrare della spesa: il noleggio non e' un
 * prestito a tasso zero, e il valore che si concede in uso al cliente e'
 * quello di listino. Lo sconto ottenuto dal fornitore e' margine di Alex,
 * non uno sconto da girare al cliente.
 *
 * Resta la possibilita' di scegliere il costo caso per caso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->string('ammortamento_base')->default('listino')->change();
        });

        // I noleggi gia' inseriti NON si toccano: cambiare la base cambia il
        // canone, e un canone gia' messo per iscritto lo si cambia a mano.
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->string('ammortamento_base')->default('costo')->change();
        });
    }
};
