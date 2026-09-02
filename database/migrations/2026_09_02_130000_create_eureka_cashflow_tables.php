<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash flow PROSPETTICO mensile (/contabilita/cashflow): l'unica cosa
        // in tutto il modulo contabile che guarda avanti invece che
        // indietro. Le partite aperte dicono cosa e' gia' andato storto,
        // questo dice quando i soldi entrano ed escono.
        //
        // Copia locale come per le altre tabelle Eureka: le schermate non
        // devono dipendere dalla disponibilita' dell'API del fornitore, che
        // e' gia' andata in disservizio sotto carico (2026-08-06) e risponde
        // 500 a raffica (vedi EurekaClient).
        Schema::create('eureka_cashflow_mesi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('anno');
            $table->unsignedTinyInteger('mese');
            $table->decimal('entrate', 14, 2)->default(0);
            $table->decimal('uscite', 14, 2)->default(0);
            // Le tre componenti separate di ciascun verso: le scadenze
            // fatture (FTC/FTF) sono impegni gia' presi, ordini e bolle
            // (OC/BC, OF/BF) sono previsioni piu' morbide. Sommarle e basta
            // farebbe sembrare certo un incasso che ancora non e' fatturato.
            $table->decimal('entrate_ftc', 14, 2)->default(0);
            $table->decimal('entrate_oc', 14, 2)->default(0);
            $table->decimal('entrate_bc', 14, 2)->default(0);
            $table->decimal('uscite_ftf', 14, 2)->default(0);
            $table->decimal('uscite_of', 14, 2)->default(0);
            $table->decimal('uscite_bf', 14, 2)->default(0);
            $table->decimal('saldo_mese', 14, 2)->default(0);
            $table->decimal('saldo_progressivo', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'anno', 'mese']);
        });

        // Le singole voci dietro il totale di un mese, per rispondere a
        // "questi 59.000 di uscite a gennaio, da cosa vengono?".
        //
        // L'anagrafica arriva solo come TESTO LIBERO: /cashflow/dettaglio non
        // espone l'id, quindi queste righe non si possono collegare ai
        // clienti del CRM. E' anche il motivo per cui le partite aperte non
        // si costruiscono da qui, pur essendo una chiamata sola per mese.
        Schema::create('eureka_cashflow_voci', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('anno');
            $table->unsignedTinyInteger('mese');
            $table->date('data_documento')->nullable();
            $table->date('data_scadenza')->nullable();
            $table->string('numero')->nullable();
            $table->string('descrizione')->nullable();
            // FTC/FTF = scadenza fattura, OC/OF = ordine, BC/BF = bolla.
            $table->string('tipo', 8)->nullable();
            $table->decimal('importo_totale', 14, 2)->default(0);
            // Segno = verso: positivo entrata, negativo uscita.
            $table->decimal('importo', 14, 2)->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'anno', 'mese']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eureka_cashflow_voci');
        Schema::dropIfExists('eureka_cashflow_mesi');
    }
};
