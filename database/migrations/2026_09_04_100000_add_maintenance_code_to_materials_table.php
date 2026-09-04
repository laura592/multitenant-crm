<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il codice della manutenzione ordinaria dovuta per un modello di macchina.
 *
 * Vive sul MODELLO a catalogo (l'articolo Eureka a cui la macchina e'
 * collegata), non sulla singola macchina: "Faema 3 gruppi" vuole F3
 * qualunque sia la matricola, e cosi' si compila una volta invece di 774.
 *
 * Il valore e' a sua volta un codice materiale (F2, F3, C3, DC2, MANA300...):
 * la riga da fatturare esiste gia' a catalogo, qui si dice solo quale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('maintenance_code')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('maintenance_code');
        });
    }
};
