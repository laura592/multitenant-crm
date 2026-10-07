<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I consumabili hanno una voce loro nel canone (07/10/2026).
 *
 * Bicchieri, palette e zucchero non sono ingredienti ne' detergenti: finivano
 * in nessun gruppo, quindi il contratto non diceva se erano compresi e il
 * canone non li copriva. Ora si elencano come le altre forniture e hanno una
 * quota propria, cosi' si vede quanto pesano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('quota_consumabili', 10, 2)->default(0)->after('quota_caffe');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn('quota_consumabili');
        });
    }
};
