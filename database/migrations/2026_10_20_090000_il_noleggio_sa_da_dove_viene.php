<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il noleggio nasce da una richiesta informazioni, come il preventivo.
 *
 * Caso vero: la richiesta RI-2026-0143 (Hotel Stalon) e' stata chiusa con il
 * contratto NOL-2026-0002, ma dalla scheda della richiesta il contratto non
 * si vedeva — l'unica traccia era una nota scritta a mano (19/10/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->foreignUuid('information_request_id')->nullable()->after('quote_id')
                ->constrained('information_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('information_request_id');
        });
    }
};
