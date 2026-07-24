<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le scadenze assicurazione/revisione dei veicoli sono migrate al modello
     * polimorfico Deadline (vedi create_deadlines_and_maintenance_tables e
     * add_amount_and_paid_at_to_deadlines_table): le colonne dedicate su
     * vehicles sono ridondanti e vanno rimosse.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['insurance_due_date', 'revision_due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('insurance_due_date')->nullable()->after('year');
            $table->date('revision_due_date')->nullable()->after('insurance_due_date');
        });
    }
};
