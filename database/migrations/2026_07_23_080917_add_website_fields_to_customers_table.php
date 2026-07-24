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
            $table->string('website')->nullable()->after('pec');
            // Distingue "verificato, non ha sito" da "non ancora controllato":
            // l'arricchimento da web copre migliaia di clienti in piu' passate,
            // serve sapere chi e' gia' stato cercato per non rifare il lavoro.
            $table->timestamp('website_checked_at')->nullable()->after('website');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['website', 'website_checked_at']);
        });
    }
};
