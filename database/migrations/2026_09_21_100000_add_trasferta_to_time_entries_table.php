<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La trasferta si segna sul turno ma vale per il giorno: basta che un turno
 * della giornata sia in trasferta perche' lo sia la giornata intera (vedi
 * App\Support\Presenze\GiornataLavorativa). Sul turno perche' e' li' che il
 * dipendente scrive gia' le sue ore: una schermata in piu' da ricordare
 * sarebbe la trasferta che nessuno segna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->boolean('trasferta')->default(false)->after('status');
            $table->string('destinazione_trasferta')->nullable()->after('trasferta');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn(['trasferta', 'destinazione_trasferta']);
        });
    }
};
