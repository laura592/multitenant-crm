<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il rapportino sa quanto è stato fatturato davvero (04/11/2026).
 *
 * Il PDF "rapportini per fattura" somma `line_total_snapshot`, che è il
 * numero del CRM: dove Eureka manda prezzo 0 l'import ci mette il listino,
 * quindi il PDF non quadra con la fattura. Sulla FT 516 di Dersut il CRM
 * diceva 2.737,13 € contro un imponibile di 1.286,76 €.
 *
 * L'importo vero sta nel documento FB, raggiungibile dall'`id_bolla` che
 * `/show/q/sl_fattura` restituisce accanto alla fattura. Gli FT da API non
 * si leggono (401), gli FB sì.
 *
 * Si tiene a parte e non si sovrascrive `line_total_snapshot`: riga per riga
 * l'abbinamento non è affidabile — sulle righe di intervento il FB porta
 * solo il testo, non il codice articolo, e su 16 righe se ne riconoscono 2.
 * Il TOTALE del documento invece è certo, perché quel documento è di quel
 * rapportino e di nessun altro.
 *
 * Due colonne e non una: senza la data non si sa se il numero è di ieri o
 * di sei mesi fa, e un importo vecchio spacciato per attuale è peggio di un
 * importo mancante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->decimal('eureka_importo_fatturato', 12, 2)->nullable()->after('eureka_fatturato_il');
            $table->timestamp('eureka_importo_letto_il')->nullable()->after('eureka_importo_fatturato');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn(['eureka_importo_fatturato', 'eureka_importo_letto_il']);
        });
    }
};
