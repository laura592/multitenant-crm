<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // sl_articolo (il bene su cui si e' intervenuto) e' un articolo
            // Eureka esattamente come i ricambi del dettaglio: stesso
            // id_eureka, stessa anagrafica sl_articolo lato loro, e infatti
            // toGestionalePayload() manda entrambi come "id_articolo". Fino a
            // qui pero' l'import lo materializzava come Product (type=service),
            // creando nel catalogo preventivi 189 macchine del parco installato
            // (Faema, Cimbali, impianti alla spina) che non sono a listino — 66
            // delle quali gia' presenti in Materiali con lo stesso codice,
            // perche' eureka:sweep-materials-catalog scandaglia tutto il
            // catalogo articoli, macchine incluse.
            //
            // Da qui i nuovi rapportini importati puntano all'articolo in
            // Materiali. machine_product_id resta finche' i 3.598 rapportini
            // storici non sono migrati (fase 2), cosi' nulla si rompe nel
            // frattempo: chi legge l'articolo prova prima il prodotto e poi il
            // materiale (vedi ServiceReport::gestionaleArticle()).
            $table->foreignUuid('machine_material_id')
                ->nullable()
                ->after('machine_product_id')
                ->constrained('materials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_material_id');
        });
    }
};
