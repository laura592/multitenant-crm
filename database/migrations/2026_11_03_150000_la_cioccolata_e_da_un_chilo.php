<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il cioccolato del listino è da 1 kg, non da 500 g (03/11/2026).
 *
 * Nel listino iniziale era stato messo da 500 g, "detto dall'ufficio il
 * 21/09/2026". Il prezzo era ed è giusto, 17,80: sbagliata era solo la
 * pezzatura, che però è quella che il cliente legge sul preventivo e quella
 * che viene congelata nella riga del noleggio (noleggio_forniture.unita)
 * quando si sceglie il prodotto.
 *
 * Nessun noleggio esistente ha righe di cioccolato, quindi non c'è niente
 * di già firmato da riscrivere: si corregge il listino e basta.
 *
 * La migration del listino iniziale non si tocca — è già girata ovunque, e
 * riscrivere una migration passata non cambia niente in produzione.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('prodotti_caffe')
            ->where('gruppo', 'liofilizzati')
            ->where('nome', 'Cioccolato')
            ->update(['formato' => '1 kg', 'prezzo' => 17.80, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('prodotti_caffe')
            ->where('gruppo', 'liofilizzati')
            ->where('nome', 'Cioccolato')
            ->update(['formato' => '500 g', 'updated_at' => now()]);
    }
};
