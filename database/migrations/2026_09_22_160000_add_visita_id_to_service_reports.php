<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I rapportini di una stessa visita (22/09/2026): la visita a passi fa un
 * rapportino per macchina, e "Dividi per macchina" ne ricava due da uno.
 * Restano documenti a se' (una scheda Eureka, un rapportino): visita_id li
 * tiene insieme solo per correggerli in un colpo ("Modifica visita").
 *
 * Nessuna tabella visite: la visita non ha dati suoi, e' solo "questi
 * rapportini vanno insieme". Nullo per tutti quelli fatti prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->uuid('visita_id')->nullable()->after('number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropIndex(['visita_id']);
            $table->dropColumn('visita_id');
        });
    }
};
