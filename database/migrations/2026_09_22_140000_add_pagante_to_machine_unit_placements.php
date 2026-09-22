<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chi paga sta sul posizionamento, non sulla macchina (22/09/2026): dipende
 * da dove la macchina e' installata, e cambia quando si sposta. Cosi' lo
 * storico dice anche chi pagava, quando.
 *
 * E' anche la forma che il dato ha su Eureka: ogni consegna in
 * art_installati ha il suo id_intestatario_fattura_f15.
 *
 * machine_units.billing_customer_id resta, come copia di quello della
 * posizione attuale: lavaggi, rapportini e preventivi lo leggono li'.
 *
 * spostamento_suggerito_pagante_code: il pagante che Eureka indica per la
 * consegna proposta dal sync, usato quando la proposta si conferma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_unit_placements', function (Blueprint $table) {
            $table->foreignUuid('billing_customer_id')->nullable()->after('customer_id')
                ->constrained('customers')->nullOnDelete();
            $table->unsignedInteger('eureka_billing_customer_code')->nullable()->after('billing_customer_id');
        });

        Schema::table('machine_units', function (Blueprint $table) {
            $table->unsignedInteger('spostamento_suggerito_pagante_code')->nullable()->after('spostamento_suggerito_motivo');
        });

        // La posizione attuale prende il pagante che la macchina ha oggi.
        // Sottoquery e non join: l'UPDATE con JOIN non c'e' su SQLite (i test).
        DB::table('machine_unit_placements')
            ->whereNull('removed_at')
            ->whereNull('deleted_at')
            ->update([
                'billing_customer_id' => DB::raw('(SELECT m.billing_customer_id FROM machine_units m WHERE m.id = machine_unit_placements.machine_unit_id)'),
                'eureka_billing_customer_code' => DB::raw('(SELECT m.eureka_billing_customer_code FROM machine_units m WHERE m.id = machine_unit_placements.machine_unit_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn('spostamento_suggerito_pagante_code');
        });

        Schema::table('machine_unit_placements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_customer_id');
            $table->dropColumn('eureka_billing_customer_code');
        });
    }
};
