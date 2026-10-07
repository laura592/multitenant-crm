<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chi riceve copia dei noleggi inviati (07/10/2026).
 *
 * L'invio del contratto mandava la copia a chi riceve i preventivi, perche'
 * era l'unica lista che somigliasse. Ma un noleggio operativo lo seguono in
 * due, non tutto l'ufficio commerciale: serve una lista sua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('notify_noleggio_emails')->nullable()->after('notify_quote_group_emails');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('notify_noleggio_emails');
        });
    }
};
