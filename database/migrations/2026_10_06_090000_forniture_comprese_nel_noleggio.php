<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cosa comprende il canone, voce per voce (06/10/2026).
 *
 * Due importi complessivi — detergenti e caffe' — bastavano al calcolo ma non
 * al contratto: il cliente deve leggere QUANTO gli spetta di ogni cosa, non
 * una cifra sola. E serve anche a noi, perche' un consumo si rinegozia voce
 * per voce: se il cacao raddoppia non si rifa' tutto il canone.
 *
 * Ogni riga porta il proprio conto (quantita' x prezzo unitario, piu' un
 * ricarico suo): le polveri hanno margini diversi dai detergenti, e il caffe'
 * diverso da entrambi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noleggio_forniture', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('noleggio_id')->constrained('noleggi')->cascadeOnDelete();
            $table->string('voce');
            // Il gruppo serve al contratto, che raccoglie le righe per
            // paragrafo invece di sciorinarle tutte di fila.
            $table->string('gruppo')->default('consumabili');
            $table->decimal('quantita', 10, 3)->default(0);
            $table->string('unita')->default('pz');
            $table->decimal('prezzo_unitario', 10, 4)->default(0);
            $table->decimal('ricarico', 5, 2)->default(0);
            $table->decimal('costo_mensile', 10, 2)->default(0);
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('ordine')->default(0);
            $table->timestamps();

            $table->index(['noleggio_id', 'ordine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noleggio_forniture');
    }
};
