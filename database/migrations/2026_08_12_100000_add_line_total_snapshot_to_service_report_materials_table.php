<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_report_materials', function (Blueprint $table) {
            // "importo" di Eureka: il totale riga (prezzo netto x quantita',
            // sconti gia' applicati, IVA esclusa) — l'unico dato che rende
            // ricostruibile il valore economico reale di un intervento
            // importato. unit_cost_snapshot da solo non basta perche' e' il
            // prezzo unitario lordo, senza sconti di riga.
            $table->decimal('line_total_snapshot', 10, 2)->nullable()->after('unit_cost_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('service_report_materials', function (Blueprint $table) {
            $table->dropColumn('line_total_snapshot');
        });
    }
};
