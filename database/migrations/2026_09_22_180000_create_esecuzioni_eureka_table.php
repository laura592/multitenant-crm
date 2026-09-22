<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ogni giro dei lavori con Eureka (notturni o lanciati a mano): quando e'
 * partito, com'e' finito, cosa ha trovato. La pagina di revisione del sync
 * lo mostra in cima (22/09/2026): l'import dei rapportini e' rimasto a zero
 * schede dal 05/09 e il sync fermo per un file mancante, e nessuno lo sapeva
 * finche' non mancavano i dati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esecuzioni_eureka', function (Blueprint $table) {
            $table->id();
            $table->string('comando', 80)->index();
            $table->timestamp('avviata_il');
            $table->timestamp('finita_il')->nullable();
            $table->string('esito', 20)->default('in_corso');
            $table->json('riepilogo')->nullable();
            $table->text('errore')->nullable();
            $table->timestamps();
            $table->index(['comando', 'avviata_il']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esecuzioni_eureka');
    }
};
