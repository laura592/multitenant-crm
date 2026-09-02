<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fotografia delle partite aperte lette da Eureka
        // (/contabilita/saldi + /contabilita/partitaaperta/{id}), per l'analisi
        // dell'esposizione e dello scaduto.
        //
        // Copia locale e non lettura a ogni apertura di pagina: servono ~143
        // chiamate per ricostruire il quadro completo (87 clienti + 56
        // fornitori con partite aperte), e l'API del fornitore e' gia' andata
        // in disservizio sotto carico (2026-08-06). Si ricarica una volta al
        // giorno e le schermate leggono da qui.
        //
        // Non si e' usata /contabilita/cashflow/dettaglio, che pure elenca le
        // scadenze in modo piu' compatto: non espone l'id dell'anagrafica ma
        // solo la ragione sociale come testo libero, quindi le righe non si
        // potrebbero collegare ai nostri clienti.
        Schema::create('eureka_partite_aperte', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // 'cliente' o 'fornitore': le due liste hanno lo stesso tracciato
            // ma vivono su rotte diverse e non condividono la numerazione.
            $table->string('tipo', 16);

            // id_nominativo su Eureka (F15ANAG.ID). Il collegamento al nostro
            // Customer resta nullable: l'anagrafica puo' non essere collegata
            // nel CRM, e i fornitori non lo sono quasi mai.
            $table->unsignedInteger('gestionale_code');
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ragione_sociale')->nullable();

            $table->unsignedSmallInteger('anno');
            $table->string('numero_fattura')->nullable();
            $table->date('data_fattura')->nullable();

            // Scadenza di riferimento della partita: la piu' recente fra i
            // movimenti a dare, cioe' la data entro cui l'intera fattura
            // avrebbe dovuto essere pagata. Con pagamenti rateizzati non si
            // puo' sapere quale rata sia stata saldata (i movimenti ad avere
            // non riportano a quale rata si riferiscono), quindi si usa
            // l'ultima: cosi' una partita risulta scaduta solo quando lo e'
            // senza ambiguita'.
            $table->date('data_scadenza')->nullable();

            $table->decimal('saldo', 12, 2);

            $table->timestamps();

            $table->index(['tenant_id', 'tipo']);
            $table->index(['tenant_id', 'data_scadenza']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eureka_partite_aperte');
    }
};
