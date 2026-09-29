<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Come si fattura a questo cliente (29/09/2026).
 *
 * L'anagrafica aveva partita IVA e codice fiscale, ma non diceva se a quel
 * cliente l'IVA si applica. Per il 99% dei clienti e' scontato; per il NATO
 * Stability Policing Centre of Excellence no: le fatture vanno emesse non
 * imponibili ex art. 72 c.1 DPR 633/1972, e quel dato viveva solo sul PDF
 * che hanno mandato.
 *
 * L'articolo e' un campo di testo libero e non un elenco chiuso: le norme
 * cambiano, e un elenco che non prevede il caso costringe a scegliere quello
 * sbagliato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('regime_iva')->default('soggetto_iva')->after('vat_number');
            $table->string('esenzione_articolo')->nullable()->after('regime_iva');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['regime_iva', 'esenzione_articolo']);
        });
    }
};
