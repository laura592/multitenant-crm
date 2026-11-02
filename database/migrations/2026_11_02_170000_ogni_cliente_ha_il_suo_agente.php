<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chi segue il cliente (02/11/2026).
 *
 * "Hotel Orizzonte e' Jesolo Modolo, Neps invece e' Guirotto": un'informazione
 * che l'ufficio ha in testa e che nel CRM non esisteva da nessuna parte. Non
 * c'e' nemmeno in Eureka, o almeno non dalla loro API: la scheda cliente
 * torna nove campi (ragione sociale, partita IVA, codice fiscale, citta',
 * provincia, email, telefono) e nessun agente, /agenti risponde 404 e le
 * "query" di /show/q/ le definiscono loro. Verificato il 02/11/2026.
 *
 * Una tabella e non un campo di testo: scritto a mano, in sei mesi lo stesso
 * agente diventa "Modolo", "modolo" e "Jesolo Modolo", e raggruppare per
 * agente non funziona piu'. Con un'anagrafica l'elenco resta corto e la
 * colonna si filtra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('nome');
            // La zona non e' il nome: "Jesolo Modolo" sono due cose, e
            // tenerle separate permette di cercare per l'una o per l'altro.
            $table->string('zona')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->boolean('attivo')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'nome']);
            $table->unique(['tenant_id', 'nome']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('agente_id')->nullable()->after('billing_customer_id');
            $table->index(['tenant_id', 'agente_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'agente_id']);
            $table->dropColumn('agente_id');
        });

        Schema::dropIfExists('agenti');
    }
};
