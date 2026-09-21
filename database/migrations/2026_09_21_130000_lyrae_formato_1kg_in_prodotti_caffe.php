<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il caffe' Lyrae e' tutto da 1 kg (detto dall'ufficio il 21/09/2026): il
 * listino iniziale lasciava vuoto il formato di Marco Polo, Dorsoduro e
 * Bucintoro. Si completa solo dove e' ancora vuoto, per non scavalcare un
 * formato gia' scritto a mano dal pannello.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('prodotti_caffe')
            ->where('nome', 'like', 'Caffè Lyrae %')
            ->whereNull('formato')
            ->update(['formato' => '1 kg', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Niente da disfare: un formato giusto non si toglie.
    }
};
