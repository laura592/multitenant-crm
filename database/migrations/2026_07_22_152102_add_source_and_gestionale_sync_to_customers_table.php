<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('source')->default('app')->after('sdi');
            $table->unsignedInteger('gestionale_code')->nullable()->after('source');
            // Valorizzato automaticamente quando un preventivo del cliente passa
            // ad "accettato": da quel momento l'anagrafica e' pronta per essere
            // inviata al gestionale.
            $table->timestamp('approved_for_gestionale_at')->nullable()->after('gestionale_code');
            // Valorizzato manualmente da chi in ufficio inserisce davvero il
            // cliente nel gestionale (nessuna integrazione automatica per ora).
            $table->timestamp('sent_to_gestionale_at')->nullable()->after('approved_for_gestionale_at');

            $table->index('source');
            $table->unique('gestionale_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['gestionale_code']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'gestionale_code', 'approved_for_gestionale_at', 'sent_to_gestionale_at']);
        });
    }
};
