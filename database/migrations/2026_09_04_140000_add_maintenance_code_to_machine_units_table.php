<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il codice manutenzione anche sulla singola macchina.
 *
 * Sul modello a catalogo (materials.maintenance_code) resta il valore
 * normale, cosi' si compila una volta per modello invece che 774 volte. Qui
 * c'e' l'eccezione: la macchina che per qualunque ragione vuole un codice
 * diverso da quello del suo modello. Vuoto = si eredita dal modello.
 *
 * Serve perche' il codice si decide guardando la macchina, non il catalogo
 * (indicazione dell'ufficio, 04/09/2026): dalla scheda della macchina si
 * deve poter dire che manutenzione e'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->string('maintenance_code')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn('maintenance_code');
        });
    }
};
