<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo sconto d'acquisto, da cui si ricava il costo (06/10/2026).
 *
 * Il canone si costruisce sul costo, ma nessuno ragiona in costi: si ragiona
 * in "quanto mi sconta Franke". Scrivendo il listino e lo sconto, il costo si
 * calcola da se' — e resta scritto quale sconto si era ipotizzato, che e' la
 * prima cosa che si dimentica e l'ultima che serve quando il canone va rivisto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('sconto_acquisto', 5, 2)->nullable()->after('listino');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn('sconto_acquisto');
        });
    }
};
