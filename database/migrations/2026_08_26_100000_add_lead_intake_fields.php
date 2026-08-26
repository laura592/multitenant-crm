<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campi per i lead che arrivano dal sito.
 *
 * Il sito invia una richiesta a /api/v1/lead; qui serve sapere da dove
 * arriva, cosa conteneva davvero il modulo, e quali consensi sono stati
 * dati — quest'ultimo punto non per formalita' ma perche' senza un flag per
 * contatto la sincronizzazione verso Brevo non ha un filtro, e soprattutto
 * il webhook di disiscrizione non ha dove scrivere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('information_requests', function (Blueprint $table) {
            // crm = inserita a mano dal pannello; sito = arrivata dal form.
            $table->string('source')->default('crm')->after('status');

            // Da quale pagina e' partita: dice quale silo converte davvero,
            // meglio di qualsiasi analytics aggregata.
            $table->string('origin_url')->nullable()->after('source');

            // Il modulo com'era al momento dell'invio. I campi del form
            // cambieranno; questo resta ed evita di perdere il contesto.
            $table->json('raw_payload')->nullable()->after('origin_url');

            // Idempotenza: e' l'id della submission lato sito. Se il job
            // riprova, la richiesta non si duplica.
            $table->string('external_id')->nullable()->after('raw_payload');
            $table->unique(['tenant_id', 'external_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('consent_privacy_at')->nullable()->after('source');
            $table->timestamp('consent_marketing_at')->nullable()->after('consent_privacy_at');

            // Da dove viene il consenso: serve a poterlo dimostrare.
            $table->string('consent_source')->nullable()->after('consent_marketing_at');
        });
    }

    public function down(): void
    {
        Schema::table('information_requests', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropColumn(['source', 'origin_url', 'raw_payload', 'external_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['consent_privacy_at', 'consent_marketing_at', 'consent_source']);
        });
    }
};
