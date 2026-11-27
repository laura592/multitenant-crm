<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Via la nota riga per riga dalle forniture dei noleggi.
 *
 * Serviva a scrivere come si era arrivati a una quantita' -- "50 ml al
 * giorno: un flacone dura 20 giorni". Il presupposto pero' e' sempre lo
 * stesso e sta gia' scritto una volta sola nel noleggio, nel campo
 * "Consumi calcolati su" (es. "250 colazioni al giorno"), che per di piu'
 * finisce nel contratto. Ripeterlo riga per riga era la stessa cosa detta
 * quaranta volte (Laura, 27/11/2026).
 *
 * Le 40 note che c'erano sono state salvate fuori dal database prima di
 * cancellarle, in Documenti/Alex, e il contratto non le ha mai stampate:
 * erano appunti interni.
 *
 * La colonna si toglie invece di lasciarla vuota: una colonna che nessuno
 * scrive e nessuno legge, fra due anni, e' solo una domanda senza
 * risposta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }

    public function down(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            // Torna la colonna, non il contenuto: quello sta nel file
            // salvato in Documenti/Alex.
            $table->string('note')->nullable()->after('costo_mensile');
        });
    }
};
