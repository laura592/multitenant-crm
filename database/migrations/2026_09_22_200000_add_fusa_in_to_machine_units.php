<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dove e' finita una macchina assorbita da una fusione.
 *
 * Senza, la macchina archiviata non diceva piu' in chi era confluita, e il
 * sync degli installati — che la ritrova per matricola, scritta come la
 * scrive Eureka — la ripristinava con un posizionamento nuovo copiato dalla
 * bolla. La fusione si riproponeva, e ogni conferma aggiungeva allo storico
 * della macchina tenuta un'altra copia della stessa consegna (22/09/2026,
 * matricola 1919045: tre volte "bolla n. 97").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->foreignUuid('fusa_in_id')->nullable()->after('fusione_suggerita_motivo')
                ->constrained('machine_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fusa_in_id');
        });
    }
};
