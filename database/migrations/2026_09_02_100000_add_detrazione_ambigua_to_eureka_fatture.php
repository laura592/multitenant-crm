<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eureka_fatture', function (Blueprint $table) {
            // Esistono righe "A DETRARRE FATTURA DI ACCONTO" senza il numero
            // dell'acconto: si sa che una detrazione c'e' stata, non quale
            // acconto chiuda. Sui dati reali sono due, entrambe di HOTEL
            // CAMBRIDGE, e finivano per lasciare il suo acconto 483/2023
            // nell'elenco dei "mai saldati" come se nessuno avesse mai
            // detratto niente.
            //
            // Un flag a parte e non e_acconto = false: dichiararlo saldato
            // sarebbe una bugia quanto dichiararlo aperto. Cosi' resta in
            // elenco, ma dichiarato per quello che e' — un caso da guardare
            // a mano, non un buco di fatturato accertato.
            $table->boolean('detrazione_ambigua')->default(false)->after('detrae_acconto_numero');
        });
    }

    public function down(): void
    {
        Schema::table('eureka_fatture', function (Blueprint $table) {
            $table->dropColumn('detrazione_ambigua');
        });
    }
};
