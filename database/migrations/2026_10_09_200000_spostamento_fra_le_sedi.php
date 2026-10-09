<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo spostamento della macchina fra le sedi del cliente (09/10/2026).
 *
 * Un'attivita' con un locale invernale e uno estivo sposta la macchina due
 * volte l'anno. Due cose vanno messe per iscritto: che si paga, e che si
 * PUO' fare -- l'art. 7 vieta al Cliente di spostare l'attrezzatura, quindi
 * senza un'eccezione esplicita il contratto direbbe una cosa e la pratica
 * un'altra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('spostamento_costo', 10, 2)->nullable()->after('installazione_costo');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn('spostamento_costo');
        });
    }
};
