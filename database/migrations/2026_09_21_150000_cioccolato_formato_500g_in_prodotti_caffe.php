<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il Cioccolato e' da 500 g (detto dall'ufficio il 21/09/2026, e cosi' lo
 * fattura il gestionale: "CIOCCOLATO SOLUBILE GR 500"): era l'ultimo formato
 * rimasto vuoto nel listino iniziale. Solo se ancora vuoto, per non
 * scavalcare un formato scritto a mano dal pannello.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('prodotti_caffe')
            ->where('nome', 'Cioccolato')
            ->whereNull('formato')
            ->update(['formato' => '500 g', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Niente da disfare: un formato giusto non si toglie.
    }
};
