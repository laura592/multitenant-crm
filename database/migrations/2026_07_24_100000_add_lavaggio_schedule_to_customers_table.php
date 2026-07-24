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
            // Cadenza lavaggi (es. 20 o 30 giorni): varia da cliente a cliente,
            // richiesto esplicitamente per poter impostare una scadenza.
            $table->unsignedSmallInteger('lavaggio_frequency_days')->nullable()->after('sent_to_gestionale_at');
            $table->date('lavaggio_next_due_date')->nullable()->after('lavaggio_frequency_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['lavaggio_frequency_days', 'lavaggio_next_due_date']);
        });
    }
};
