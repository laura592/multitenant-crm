<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            // Fase 3: la matricola nasce da un articolo. product_id copre solo
            // le macchine a listino (catalogo commerciale), e i selettori del
            // modello filtrano type=machine: il parco installato storico —
            // Faema, Cimbali, impianti alla spina — non era selezionabile da
            // nessuna parte, tant'e' che 788 macchinari su 789 avevano solo
            // model_name come testo libero. material_id e' l'articolo Eureka,
            // la stessa anagrafica che il rapportino usa in
            // service_reports.machine_material_id.
            //
            // Restano entrambi, non in alternativa: una macchina che vendiamo
            // noi e' un prodotto a listino E un articolo di gestionale.
            $table->foreignUuid('material_id')
                ->nullable()
                ->after('product_id')
                ->constrained('materials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_id');
        });
    }
};
