<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il codice operatore che Eureka usa sulle schede lavoro (sl_id_operatore).
 *
 * Le schede che mandiamo noi uscivano tutte con l'operatore 2, il default
 * del gestionale: l'amministrazione non riusciva a vedere di chi fosse
 * l'intervento e il tecnico serve per fatturare (1 = Alessandro,
 * 5 = Igor). Non e' derivabile dall'email ne' dal nome: la corrispondenza
 * vive solo nell'anagrafica operatori di Eureka, quindi va scritta qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('gestionale_operatore_id')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('gestionale_operatore_id'));
    }
};
