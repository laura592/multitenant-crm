<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'offerta caffe' diventa un documento salvato, come il preventivo: si
 * prepara, si guarda il PDF, si manda quando si vuole, e resta lo storico di
 * cosa e' stato offerto a chi (21/09/2026). Prima esisteva solo dentro una
 * finestra: chiusa quella, sparita.
 *
 * Le righe si salvano per intero (nome, formato, prezzo) e non come
 * riferimento al listino: l'offerta deve continuare a dire il prezzo
 * promesso anche dopo che il listino cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offerte_caffe', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->date('date');
            $table->date('valida_fino')->nullable();
            $table->json('righe');
            $table->text('note')->nullable();
            $table->string('status')->default('bozza');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'number']);
        });

        Schema::create('offerta_caffe_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('offerta_caffe_id')->constrained('offerte_caffe')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Mandata insieme a un preventivo o a un'offerta di gruppo: qui
            // si annota con quale, per ritrovarla.
            $table->string('inviata_con')->nullable();
            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject')->nullable();
            $table->longText('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offerta_caffe_emails');
        Schema::dropIfExists('offerte_caffe');
    }
};
