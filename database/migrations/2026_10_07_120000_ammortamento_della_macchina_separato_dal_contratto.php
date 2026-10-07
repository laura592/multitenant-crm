<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'ammortamento della macchina non coincide col contratto (07/10/2026).
 *
 * La quota macchina si calcolava sul costo d'acquisto spalmato su tutta la
 * durata. Ma un noleggio di cinque anni puo' voler rientrare in tre, e il
 * valore da recuperare puo' essere il listino e non il costo: da li' in poi
 * quella quota resta nel canone ed e' margine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->string('ammortamento_base')->default('costo')->after('costo');
            $table->unsignedSmallInteger('ammortamento_mesi')->nullable()->after('ammortamento_base');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn(['ammortamento_base', 'ammortamento_mesi']);
        });
    }
};
