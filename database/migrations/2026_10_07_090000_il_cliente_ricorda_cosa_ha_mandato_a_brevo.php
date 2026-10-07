<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chi ha dato il consenso marketing nel CRM va su Brevo (07/10/2026).
 *
 * Il sync gira ogni ora: senza ricordarsi cosa ha gia' mandato rispedirebbe
 * migliaia di contatti identici a ogni giro. L'impronta dei dati inviati
 * dice se c'e' qualcosa di nuovo; la data serve anche a capire chi era su
 * Brevo quando il consenso viene revocato (va messo in blacklist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('brevo_synced_at')->nullable()->after('consent_source');
            $table->string('brevo_sync_hash', 40)->nullable()->after('brevo_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['brevo_synced_at', 'brevo_sync_hash']);
        });
    }
};
