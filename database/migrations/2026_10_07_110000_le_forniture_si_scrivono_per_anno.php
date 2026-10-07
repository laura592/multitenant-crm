<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le quantita' delle forniture passano da mensili ad annue (07/10/2026).
 *
 * Il contratto le quantita' le dice per anno -- "900 kg di caffe'" -- ma nel
 * prospetto si scrivevano al mese, e chi compila doveva dividere a mente per
 * dodici. Il verso giusto e' l'inverso: si scrive quello che si promette al
 * cliente, e il mensile se lo calcola il programma.
 *
 * I valori esistenti si moltiplicano per dodici: significano la stessa cosa,
 * cambia solo l'unita' di tempo in cui sono espressi. Il canone non si muove.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('noleggio_forniture')->update(['quantita' => DB::raw('quantita * 12')]);
    }

    public function down(): void
    {
        DB::table('noleggio_forniture')->update(['quantita' => DB::raw('quantita / 12')]);
    }
};
