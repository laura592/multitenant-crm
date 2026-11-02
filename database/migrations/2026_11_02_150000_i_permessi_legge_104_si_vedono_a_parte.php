<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I permessi della Legge 104 si contano a parte (02/11/2026).
 *
 * Finora erano permessi come gli altri, con "Legge 104" scritto nelle note:
 * nel riepilogo mensile finivano dentro "Ore permesso" insieme al dentista e
 * all'ora di anticipo, e chi prepara le buste non li distingueva piu'. Ma
 * sono una cosa diversa — li rimborsa l'INPS e hanno un tetto mensile — e il
 * commercialista li vuole separati (Laura, 02/11/2026: "nei riepiloghi la
 * 104 la devo vedere").
 *
 * Un flag e non un tipo nuovo: la 104 E' un permesso, con le stesse ore, le
 * stesse approvazioni e le stesse regole di calcolo. Cambiare il tipo avrebbe
 * voluto dire toccare form, filtri, policy e i conti dei giorni per
 * distinguere una cosa che si distingue con una casella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('legge_104')->default(false)->after('type');
        });

        // I permessi 104 gia' inseriti li riconosce la nota con cui sono stati
        // scritti: senza questo, ottobre di Cristina resterebbe indistinto
        // proprio nel mese per cui e' nata la colonna.
        DB::table('leave_requests')
            ->where('type', 'permesso')
            ->where('notes', 'like', 'Legge 104%')
            ->update(['legge_104' => true]);
    }

    public function down(): void
    {
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn('legge_104'));
    }
};
