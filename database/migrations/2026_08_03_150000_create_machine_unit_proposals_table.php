<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proposte di NUOVE MachineUnit trovate su Eureka (/show/q/art_installati)
 * che non esistono ancora nel CRM — a differenza di gestionale_suggested_code
 * (che propone un collegamento su un record gia' esistente), qui il record
 * stesso non esiste ancora, quindi serve una tabella a parte invece di un
 * semplice campo su MachineUnit. Confermare crea davvero la MachineUnit (e
 * il Product se mancava); scartare cancella solo la proposta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_unit_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number');
            $table->string('model_name')->nullable();
            $table->unsignedInteger('eureka_article_id');
            $table->string('eureka_article_code')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_unit_proposals');
    }
};
