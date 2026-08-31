<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            // Numero di vie lavate dichiarato dal tecnico nella scorciatoia
            // "Lavaggio eseguito". Prima non veniva salvato da nessuna parte:
            // si ricavava all'indietro dalle righe materiali (2 + quantita'
            // ULTVIA), formula che non sa distinguere 1 via da 2 — entrambe
            // generano il solo LAV2 — e quindi rileggeva sempre 2 anche dopo
            // aver scritto 1. Le righe materiali (e quindi il prezzo) non
            // cambiano: questa colonna serve solo a ricordare il valore
            // digitato. Resta nullable per i rapportini vecchi e per quelli
            // importati da Eureka, dove il dato non c'e' mai stato.
            $table->unsignedSmallInteger('lavaggio_vie_count')->nullable()->after('work_performed');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn('lavaggio_vie_count');
        });
    }
};
