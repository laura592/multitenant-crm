<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'installazione fatturata a parte (09/10/2026).
 *
 * Il contratto dava per compresa "consegna, installazione e allacciamento".
 * Su un noleggio di stagione non regge: la macchina arriva a novembre e
 * rientra ad aprile, e due viaggi con il tecnico non si ammortizzano in
 * cinque mesi di canone. Si fattura una tantum e il contratto deve dirlo,
 * altrimenti il cliente la crede inclusa -- l'art. 5 glielo prometteva.
 *
 * Nullo = compresa nel canone, come prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('installazione_costo', 10, 2)->nullable()->after('base_consumo');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn('installazione_costo');
        });
    }
};
