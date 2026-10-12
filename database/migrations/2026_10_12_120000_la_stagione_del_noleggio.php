<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le date vere di un noleggio stagionale (12/10/2026).
 *
 * "L'hotel aprira' al pubblico il 1 dicembre 2026 e chiudera' il 14 marzo
 * 2027. Chiaramente avro' bisogno che la macchina sia montata tra il periodo
 * che va dal 16 novembre al 25 novembre" (Hotel Stalon, 12/10/2026).
 *
 * Con la sola durata in mesi quel contratto non si scrive: "4 mesi dal
 * 01/12/2026" scade il 31/03, due settimane dopo la chiusura dell'hotel, e
 * la finestra di montaggio — che sta prima della decorrenza — non ha dove
 * stare. La durata in mesi resta, serve a riportare le forniture annue al
 * periodo; queste date, se valorizzate, sono quelle che il contratto scrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->date('data_fine')->nullable()->after('data_inizio');
            $table->date('consegna_dal')->nullable()->after('data_fine');
            $table->date('consegna_al')->nullable()->after('consegna_dal');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn(['data_fine', 'consegna_dal', 'consegna_al']);
        });
    }
};
