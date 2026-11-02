<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trasferta e pausa diventano una cosa del singolo dipendente (02/11/2026).
 *
 * Igor e' in giro tutti i giorni: la trasferta non e' l'eccezione da
 * spuntare, e' la sua giornata normale. E la sua indennita' si porta dentro
 * un'ora di straordinario — se timbra dalle 8 alle 19, un'ora e' di pausa,
 * due sono oltre il contratto e una di quelle la paga gia' la trasferta.
 *
 * Fino a oggi quelle due regole stavano in config/presenze.php, uguali per
 * tutti, con una data di stacco: un'ora fino al 30/09/2026, zero dal 01/10.
 * Quella resta la regola dell'azienda. Qui si aggiunge la possibilita' di
 * scriverne una diversa sul profilo di una persona, e quando c'e' vince
 * sempre, anche sui mesi passati (indicazione di Laura, 02/11/2026: "da
 * sempre").
 *
 * Tre campi, tutti vuoti per chi non ha niente di diverso dagli altri:
 * lasciandoli vuoti il calcolo e' identico a prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // La trasferta nasce gia' spuntata sulle sue timbrature.
            $table->boolean('trasferta_predefinita')->default(false)->after('default_afternoon_out');

            // Quanta pausa toglie la sua giornata quando la pausa non e' gia'
            // visibile nel cartellino (vedi GiornataLavorativa::lavorateNette).
            $table->unsignedSmallInteger('pausa_minuti')->nullable()->after('trasferta_predefinita');

            // Ore di straordinario comprese nella sua indennita' di trasferta.
            // Null = vale la regola generale di config/presenze.php.
            $table->decimal('trasferta_ore_incluse', 4, 2)->nullable()->after('pausa_minuti');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'trasferta_predefinita',
            'pausa_minuti',
            'trasferta_ore_incluse',
        ]));
    }
};
