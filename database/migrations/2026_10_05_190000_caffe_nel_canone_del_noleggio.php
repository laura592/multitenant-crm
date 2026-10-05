<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il caffe' entra nel canone (05/10/2026).
 *
 * Era rimasto fuori di proposito — fatturato a consumo, perche' chi consuma
 * piu' del previsto erode il margine e chi consuma meno si sente truffato —
 * ma la scelta e' commerciale, non tecnica: Laura lo vuole dentro, cosi' il
 * cliente ha un numero solo al mese e non due fatture da conciliare.
 *
 * Ha la sua leva di ricarico, separata da quella dei detergenti: le due si
 * decidono in momenti diversi e con margini diversi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('caffe_mese', 10, 2)->default(0)->after('ricarico_detergenti');
            $table->decimal('ricarico_caffe', 5, 2)->default(0)->after('caffe_mese');
            $table->decimal('quota_caffe', 10, 2)->default(0)->after('quota_detergenti');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn(['caffe_mese', 'ricarico_caffe', 'quota_caffe']);
        });
    }
};
