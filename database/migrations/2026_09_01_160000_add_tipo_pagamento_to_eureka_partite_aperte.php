<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eureka_partite_aperte', function (Blueprint $table) {
            // Modalita' di pagamento concordata (Bonifico, Rimessa Diretta,
            // RiBa...), letta dai movimenti della partita su Eureka.
            //
            // Serve a chi sollecita: dice COME il cliente deve pagare, ed e'
            // la prima cosa da sapere al telefono. Cambia anche se ha senso
            // telefonare: una RiBa la presenta la banca, non la paga il
            // cliente, quindi sollecitarlo sarebbe sbagliato. Sui clienti di
            // ALEX oggi compaiono solo Bonifico e Rimessa Diretta, ma la
            // colonna e' libera perche' la tabella pagamenti di Eureka ne
            // prevede molte altre.
            $table->string('tipo_pagamento')->nullable()->after('data_scadenza');
        });
    }

    public function down(): void
    {
        Schema::table('eureka_partite_aperte', function (Blueprint $table) {
            $table->dropColumn('tipo_pagamento');
        });
    }
};
