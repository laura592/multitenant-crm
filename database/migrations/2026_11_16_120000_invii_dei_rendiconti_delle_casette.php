<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storico degli invii del rendiconto di una lettura (16/11/2026).
 *
 * Stessa forma degli invii di rapportini, preventivi e noleggi. Serve a
 * rispondere alla domanda che arriva a fine mese -- "la lettura di ottobre
 * ve l'abbiamo mandata?" -- senza doverla cercare nella posta inviata, e a
 * non far sparire in silenzio un invio fallito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lettura_casetta_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lettura_casetta_id')->constrained('letture_casette')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject')->nullable();
            $table->longText('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['lettura_casetta_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lettura_casetta_emails');
    }
};
