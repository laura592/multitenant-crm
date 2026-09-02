<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copia locale delle fatture registrate in contabilita' su Eureka
        // (/contabilita/fatture/clienti e /fornitori), ~2000 documenti.
        //
        // A differenza delle partite aperte, questi dati sono AFFIDABILI:
        // li abbiamo confrontati con i PDF reali delle fatture e coincidono.
        // Le partite invece divergono dall'estratto conto del gestionale
        // (vedi eureka_partite_aperte e il caso 3068), quindi le analisi si
        // costruiscono di preferenza qui.
        //
        // Serve a piu' cose: acconti senza saldo, verifica del soggetto
        // effettivamente fatturato, stagionalita' del fatturato.
        Schema::create('eureka_fatture', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('tipo', 16); // cliente | fornitore
            $table->unsignedInteger('id_eureka')->comment('id del documento in contabilita');

            $table->unsignedInteger('gestionale_code')->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ragione_sociale')->nullable();
            $table->string('partita_iva', 32)->nullable();

            $table->string('numero_doc')->nullable();
            $table->date('data_doc')->nullable();
            $table->decimal('totale_doc', 12, 2)->default(0);
            $table->decimal('imponibile', 12, 2)->default(0);

            // Condizioni di pagamento (B001, R041, D010...): distinguono
            // bonifico, rimessa diretta e RiBa. Le RiBa non compaiono mai
            // fra le partite aperte, quindi questo campo e' l'unico modo di
            // sapere quanta parte del fatturato viaggia su effetti.
            $table->string('pagamento', 16)->nullable();
            $table->string('causale', 16)->nullable();

            // Documento B10 di origine: serve per /report/fattura/{id}, che
            // vuole QUESTO id e non quello contabile.
            $table->unsignedInteger('id_b10_origine')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'tipo', 'id_eureka']);
            $table->index(['tenant_id', 'tipo', 'data_doc']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eureka_fatture');
    }
};
