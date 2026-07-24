<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deadline::TYPE_BOLLO ('bollo') esiste nel modello (usato da
     * VehicleResource per la scadenza bollo auto) ma non era mai stato
     * aggiunto all'enum di colonna: qualunque insert/update con questo tipo
     * falliva contro il vincolo CHECK/ENUM del DB (vedi DeadlineRenewalTest).
     * Non si tocca la migration originale (gia' eseguita/committata): si
     * altera la colonna qui, in una migration successiva.
     */
    public function up(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->enum('type', [
                'assicurazione', 'bollo', 'revisione', 'polizza_rct', 'manutenzione_ordinaria', 'licenza', 'contratto', 'altro',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->enum('type', [
                'assicurazione', 'revisione', 'polizza_rct', 'manutenzione_ordinaria', 'licenza', 'contratto', 'altro',
            ])->change();
        });
    }
};
