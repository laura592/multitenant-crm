<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due colonne dei noleggi che nessun codice leggeva (12/10/2026).
 *
 * caffe_kg_mese e detergenti_inclusi erano il primo modo di dire le
 * forniture, prima che arrivassero le righe per prodotto: comparivano solo
 * in $fillable e $casts del modello — nessun campo nel form, nessun calcolo,
 * nessuna vista. Sul contratto NATO contenevano "75 kg al mese" e "25
 * pastiglie e 1 flacone detergente latte al mese", informazioni che le sue
 * sette righe di fornitura dicono meglio (890 kg di caffe' e 360 pastiglie
 * l'anno) e che il canone usa davvero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn(['caffe_kg_mese', 'detergenti_inclusi']);
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('caffe_kg_mese', 10, 2)->nullable()->after('ricarico_caffe');
            $table->string('detergenti_inclusi')->nullable()->after('caffe_kg_mese');
        });
    }
};
