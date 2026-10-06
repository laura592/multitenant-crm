<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storico degli invii del contratto di noleggio (06/10/2026).
 *
 * Stessa forma degli invii di preventivi e offerte caffe': quando il cliente
 * dice "non mi e' mai arrivato" serve poter rispondere con data, destinatario
 * e chi l'ha mandato. E un invio fallito va registrato come gli altri, perche'
 * il contratto che non e' partito e' il peggiore da scoprire per caso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noleggio_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('noleggio_id')->constrained('noleggi')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject')->nullable();
            $table->longText('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['noleggio_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noleggio_emails');
    }
};
