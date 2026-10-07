<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Su quale utilizzo sono stati calcolati i consumi (07/10/2026).
 *
 * I quantitativi dell'art. 5 non nascono dal nulla: per il NATO vengono da
 * 250 colazioni al giorno. Senza quel riferimento scritto, fra due anni
 * nessuno sa piu' perche' erano 900 kg di caffe' e non 600, e se il cliente
 * raddoppia il servizio non c'e' niente a cui appellarsi per rivedere il
 * canone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->string('base_consumo')->nullable()->after('mesi');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn('base_consumo');
        });
    }
};
