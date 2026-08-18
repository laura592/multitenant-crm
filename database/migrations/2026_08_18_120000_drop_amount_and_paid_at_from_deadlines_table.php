<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lo scadenzario e' solo un calendario di conformita' (assicurazione, bollo,
// revisione, leasing...), non un tracciamento costi: l'utente aveva gia'
// chiesto di non avere un campo importo, come per "notes" (vedi migrazione
// 2026_08_17_090000). Rimossi qui perche' non erano mai stati tolti dal DB
// nonostante l'indicazione.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->dropColumn(['amount', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('due_date');
            $table->date('paid_at')->nullable()->after('amount');
        });
    }
};
