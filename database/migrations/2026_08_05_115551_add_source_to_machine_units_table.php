<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue i macchinari inseriti a mano da quelli creati automaticamente
 * dal sync notturno (art_installati, vedi GestionaleSyncRunner) — permette
 * di mostrare "importate di recente" senza una coda di conferma manuale
 * (quella resta solo per clienti/fatturazione).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->string('source')->default('manuale')->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
