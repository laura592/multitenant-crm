<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La riga di fornitura si ricorda da quale listino e' stata scelta (06/10/2026).
 *
 * Le due tendine — listino caffe' e magazzino materiali — erano solo un aiuto
 * alla compilazione: riempivano voce, unita' e prezzo e poi sparivano, perche'
 * la scelta non veniva salvata da nessuna parte. Riaprendo la scheda
 * risultavano vuote, e sembrava che la selezione non fosse mai stata fatta.
 *
 * Tenendo il riferimento si ottiene anche altro: si sa sempre quale prodotto
 * del listino il contratto ha promesso, e si puo' accorgersi che il prezzo
 * nel frattempo e' cambiato.
 *
 * Nullable, e non obbligatorio: i detergenti che Alex usa davvero non stanno
 * in nessun listino e restano scritti a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            // nullOnDelete e non cascade: se una voce di listino viene
            // cancellata la fornitura resta — il contratto l'ha gia'
            // promessa, e cancellarne la riga falserebbe il canone.
            $table->foreignUuid('prodotto_caffe_id')->nullable()->after('voce')
                ->constrained('prodotti_caffe')->nullOnDelete();
            $table->foreignUuid('material_id')->nullable()->after('prodotto_caffe_id')
                ->constrained('materials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('noleggio_forniture', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prodotto_caffe_id');
            $table->dropConstrainedForeignId('material_id');
        });
    }
};
