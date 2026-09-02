<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Il saldo che EUREKA calcola per ciascuna anagrafica
        // (/contabilita/saldi), tenuto accanto alle partite che quel saldo
        // dovrebbe comporre.
        //
        // Serve a una cosa sola: sapere quando i due numeri non coincidono.
        // Le partite hanno problemi di affidabilita' noti — divergono
        // dall'estratto conto del gestionale e non vedono il portafoglio
        // RiBa — ma finora la divergenza si scopriva solo aprendo Eureka a
        // mano, cliente per cliente. Costa zero chiamate in piu': l'elenco
        // dei saldi lo scarichiamo gia' per sapere a chi chiedere il
        // dettaglio, e finora buttavamo via l'importo.
        Schema::create('eureka_saldi_anagrafiche', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 16); // cliente | fornitore
            $table->unsignedInteger('gestionale_code');
            $table->string('ragione_sociale')->nullable();
            // Il saldo secondo Eureka, non secondo noi.
            $table->decimal('saldo', 12, 2);
            $table->timestamps();
            $table->unique(['tenant_id', 'tipo', 'gestionale_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eureka_saldi_anagrafiche');
    }
};
