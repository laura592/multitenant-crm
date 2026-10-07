<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il prezzo d'acquisto delle forniture (07/10/2026).
 *
 * Nelle righe c'era un prezzo solo, e il gestionale non sapeva se fosse
 * quello che Alex paga o quello a cui rivende: con il ricarico a zero
 * concludeva "margine zero", che e' una conclusione nata dal non sapere.
 * I prezzi scritti sono di VENDITA (Laura, 07/10/2026), quindi il costo
 * va chiesto a parte.
 *
 * Facoltativo: senza, la scheda dice che il margine non si puo' calcolare,
 * invece di dichiararlo nullo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            $table->decimal('prezzo_acquisto', 10, 4)->nullable()->after('prezzo_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            $table->dropColumn('prezzo_acquisto');
        });
    }
};
