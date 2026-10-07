<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I noleggi si numerano come i preventivi (07/10/2026).
 *
 * Senza numero un noleggio si nomina col cliente, e due proposte allo stesso
 * cliente diventano indistinguibili al telefono. NOL-2026-0001, come
 * PRV- per i preventivi e RI- per le richieste: la numerazione e' per
 * tenant e per anno (docs/architecture.md §10.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->string('number')->nullable()->after('id');
            $table->index(['tenant_id', 'number']);
        });

        // I noleggi gia' inseriti prendono il numero nell'ordine in cui sono
        // nati: senza, resterebbero gli unici senza e andrebbero numerati a
        // mano proprio mentre si usa il numero per cercarli.
        $perTenant = [];

        foreach (DB::table('noleggi')->orderBy('created_at')->get(['id', 'tenant_id', 'created_at']) as $n) {
            $anno = date('Y', strtotime($n->created_at));
            $chiave = $n->tenant_id.'|'.$anno;
            $perTenant[$chiave] = ($perTenant[$chiave] ?? 0) + 1;

            DB::table('noleggi')->where('id', $n->id)->update([
                'number' => 'NOL-'.$anno.'-'.str_pad((string) $perTenant[$chiave], 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'number']);
            $table->dropColumn('number');
        });
    }
};
