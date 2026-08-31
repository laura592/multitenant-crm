<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indici sulle colonne su cui l'elenco rapportini ordina e filtra.
 *
 * La tabella aveva indici su tutte le chiavi esterne ma nessuno su
 * intervention_date (ordinamento predefinito) e status (filtro piu' usato).
 * Con 3.651 righe MySQL se la cava leggendo tutto; e' la classica cosa che
 * smette di cavarsela a qualche decina di migliaia, quando ormai e' tardi.
 *
 * L'indice composto (tenant_id, intervention_date) copre il caso reale:
 * l'elenco e' sempre filtrato per tenant e poi ordinato per data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->index(['tenant_id', 'intervention_date'], 'sr_tenant_data_index');
            $table->index('status', 'sr_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropIndex('sr_tenant_data_index');
            $table->dropIndex('sr_status_index');
        });
    }
};
