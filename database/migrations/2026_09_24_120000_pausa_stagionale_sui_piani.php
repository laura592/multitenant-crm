<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pausa stagionale sui piani di manutenzione e lavaggio (24/09/2026).
 *
 * Campeggi, chioschi e stabilimenti chiudono a ottobre e riaprono a
 * primavera: fino a ieri il piano continuava a scadere e a finire nei
 * promemoria per tutto l'inverno, con il tecnico che riceveva la lista di
 * locali chiusi. Chiudere il piano non andava bene — a marzo bisognava
 * ricordarsi di riaprirlo, e lo storico si spezzava.
 *
 * Qui il piano si mette in pausa: resta attivo e con la sua storia, ma non
 * scade e non entra nei promemoria finche' non si riapre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            // Vuoto = in pausa a tempo indeterminato (si riapre a mano).
            // Con una data = riprende da solo quel giorno.
            $table->date('in_pausa_fino_al')->nullable()->after('status');
            $table->boolean('in_pausa')->default(false)->after('status');
            $table->string('pausa_motivo')->nullable()->after('in_pausa_fino_al');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropColumn(['in_pausa', 'in_pausa_fino_al', 'pausa_motivo']);
        });
    }
};
