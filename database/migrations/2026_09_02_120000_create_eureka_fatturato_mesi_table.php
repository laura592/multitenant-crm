<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fatturato per mese come lo calcola Eureka (/contabilita/fatturato).
        //
        // Non si ricava dalla nostra copia delle fatture, pur avendola: il
        // netto di Eureka e la somma dei nostri imponibili non coincidono
        // (424.548,69 contro 438.484,17 sul 2026, misurati il 2026-09-02)
        // perche' Eureka pesa le causali col piano dei conti e filtra sulla
        // data di REGISTRAZIONE, non su quella del documento. Rifarlo a mano
        // vorrebbe dire reimplementare la contabilita' del gestionale e
        // sbagliarla; questo e' il numero che l'ufficio vede su Eureka.
        Schema::create('eureka_fatturato_mesi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 16); // cliente | fornitore
            $table->unsignedSmallInteger('anno');
            $table->unsignedTinyInteger('mese');
            $table->decimal('dare', 14, 2)->default(0);
            $table->decimal('avere', 14, 2)->default(0);
            $table->decimal('netto', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'tipo', 'anno', 'mese']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eureka_fatturato_mesi');
    }
};
