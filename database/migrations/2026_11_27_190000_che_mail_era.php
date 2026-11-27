<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Che mail era: l'invio del preventivo, o un sollecito?
 *
 * Le due cose finiscono nella stessa tabella -- ed e' giusto, perche' sono
 * tutte e due mail che il cliente ha ricevuto davvero, e QuoteClientController
 * ci legge gli indirizzi gia' usati. Ma la pagina "Da sollecitare" legge
 * quelle stesse righe come "ultimo invio dell'offerta": senza sapere che
 * mail era, il giorno dopo il primo sollecito scriverebbe "rimandato il
 * 27/11" su un preventivo che nessuno ha rimandato.
 *
 * Sarebbe una bugia esattamente nel punto che si guarda per decidere la
 * frase da dire al telefono.
 *
 * Il valore di partenza sistema da solo le righe storiche: tutto quello che
 * c'e' adesso e' l'invio di un preventivo, perche' il sollecito non si e'
 * mai potuto mandare.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['quote_emails', 'quote_group_emails'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table) {
                $table->string('tipo', 20)->default('preventivo')->after('status')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['quote_emails', 'quote_group_emails'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table) {
                $table->dropColumn('tipo');
            });
        }
    }
};
