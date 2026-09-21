<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risposta del cliente dal link nella mail del preventivo (21/09/2026):
 * molti preventivi restavano "Inviato" per sempre, senza sapere se il
 * cliente li avesse almeno aperti. Ora la mail porta a una pagina dove il
 * cliente accetta firmando, rifiuta, fa una domanda o chiede di essere
 * richiamato.
 *
 * Il token sta sul documento che si invia: il preventivo singolo o l'offerta
 * globale (che raccoglie piu' soluzioni alternative). Le visite si contano su
 * entrambi, cosi' "visto dal cliente" si legge anche sul singolo preventivo
 * di un'offerta.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotes', 'quote_groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('public_token', 64)->nullable()->unique();
                $table->timestamp('client_first_viewed_at')->nullable();
                $table->timestamp('client_last_viewed_at')->nullable();
                $table->unsignedInteger('client_view_count')->default(0);
            });
        }

        Schema::create('quote_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            // La soluzione a cui si riferisce la risposta: sempre valorizzata
            // su accettazione, vuota su una domanda fatta sull'offerta intera.
            $table->foreignUuid('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('quote_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // accettato | rifiutato | domanda | richiamata
            $table->string('signer_name')->nullable();
            $table->string('signer_role')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('preferred_time', 30)->nullable();
            $table->string('reason', 40)->nullable();
            $table->text('message')->nullable();
            // Prova dell'accettazione: la firma disegnata e il PDF esatto che
            // il cliente aveva davanti, perche' il preventivo si puo' ancora
            // modificare dopo.
            $table->string('signature_path')->nullable();
            $table->string('accepted_pdf_path')->nullable();
            $table->string('accepted_pdf_sha256', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->foreignUuid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'handled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_responses');

        foreach (['quotes', 'quote_groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['public_token']);
                $table->dropColumn(['public_token', 'client_first_viewed_at', 'client_last_viewed_at', 'client_view_count']);
            });
        }
    }
};
