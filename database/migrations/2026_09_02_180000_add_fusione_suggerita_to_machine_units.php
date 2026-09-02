<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La proposta di fusione fra due macchine che sono la stessa.
 *
 * Stessa forma delle proposte di doppione sui rapportini
 * (duplicato_suggerito_id): il sync propone, una persona conferma. Fondere
 * due macchine sposta rapportini e storico di manutenzione, quindi non si
 * decide da soli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->foreignUuid('fusione_suggerita_id')->nullable()->after('serial_number')
                ->constrained('machine_units')->nullOnDelete();
            $table->string('fusione_suggerita_motivo')->nullable()->after('fusione_suggerita_id');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fusione_suggerita_id');
            $table->dropColumn('fusione_suggerita_motivo');
        });
    }
};
