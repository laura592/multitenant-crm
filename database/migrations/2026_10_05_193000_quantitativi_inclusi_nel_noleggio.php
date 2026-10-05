<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quanto caffe' e quanto detersivo sono compresi (05/10/2026).
 *
 * Gli importi mensili dicono quanto COSTA la fornitura; il contratto deve
 * dire quanta ne spetta. Senza un quantitativo, "compreso nel canone" e' una
 * porta aperta: il cliente consuma quanto vuole e la differenza e' nostra, che
 * e' esattamente il rischio per cui il caffe' era stato tenuto fuori.
 *
 * Caffe' in kg, che e' l'unita' in cui si ordina e si contesta. Detergenti in
 * testo libero: sono pastiglie, flaconi e filtri insieme, e un numero solo non
 * direbbe niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('caffe_kg_mese', 8, 2)->nullable()->after('ricarico_caffe');
            $table->string('detergenti_inclusi')->nullable()->after('ricarico_detergenti');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn(['caffe_kg_mese', 'detergenti_inclusi']);
        });
    }
};
