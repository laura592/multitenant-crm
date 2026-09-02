<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eureka_fatture', function (Blueprint $table) {
            // Le fatture di acconto e le righe che le detraggono si
            // riconoscono SOLO dal testo della riga documento ("FATTURA DI
            // ACCONTO PARI AL 50%...", "A DETRARRE FATTURA DI ACCONTO NR X"):
            // la lista fatture non ha un campo che distingua FA/FAD da FT, e
            // la causale contabile e' 101 per entrambe.
            //
            // Si marcano in fase di import, con una ricerca full-text sulle
            // righe, per non doverla rifare a ogni apertura di pagina.
            $table->boolean('e_acconto')->default(false)->after('causale');

            // Numero della fattura di acconto che QUESTO documento detrae.
            // Un acconto senza nessun documento che lo detrae e' una
            // fornitura mai saldata: fatturato che non e' mai stato emesso.
            $table->string('detrae_acconto_numero')->nullable()->after('e_acconto');
        });
    }

    public function down(): void
    {
        Schema::table('eureka_fatture', function (Blueprint $table) {
            $table->dropColumn(['e_acconto', 'detrae_acconto_numero']);
        });
    }
};
