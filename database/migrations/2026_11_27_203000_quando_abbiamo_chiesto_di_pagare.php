<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il registro dei solleciti di pagamento mandati ai clienti.
 *
 * Lo scaduto si telefona da mesi e non resta traccia di niente: due persone
 * possono chiamare lo stesso cliente lo stesso giorno, e chi apre la pagina
 * non sa se la settimana scorsa gli abbiamo gia' scritto. Da qui nasce la
 * colonna "Sollecitato il" e la riga in cima al modulo d'invio.
 *
 * PERCHE' UNA FOTOGRAFIA E NON UN COLLEGAMENTO ALLE PARTITE.
 * `eureka_partite_aperte` non e' un archivio: e' l'istantanea che l'import
 * ricarica due volte al giorno, e una partita sparisce nel momento in cui la
 * fattura viene incassata. Se il sollecito puntasse alle partite, la
 * settimana dopo racconterebbe una storia che non esiste piu': il cliente
 * paga, le righe spariscono, e il sollecito da 2.633,44 EUR diventa un
 * sollecito da zero euro per nessuna fattura. Qui dentro si congela quello
 * che gli abbiamo chiesto QUEL giorno — numero, anno, importo, residuo di
 * ogni partita, e i nomi dei file allegati — cosi' fra sei mesi si puo'
 * ancora rispondere a "ma voi cosa mi avevate mandato?".
 *
 * gestionale_code e non solo customer_id: la partita sta sull'anagrafica
 * Eureka a cui la fattura e' INTESTATA, e tre di quelle anagrafiche non
 * hanno nemmeno una scheda nel CRM (Castello di Roncade, Caffea, Vesuvio).
 * Il codice c'e' sempre, il customer_id no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solleciti_pagamento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // L'anagrafica Eureka e' la chiave vera (vedi il commento di
            // classe); la scheda CRM e' un di piu', e se qualcuno la
            // cancella il sollecito resta.
            $table->unsignedInteger('gestionale_code');
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();

            // Chi ha premuto il pulsante. Nullable solo per non perdere il
            // registro quando un utente viene rimosso: nessun automatismo
            // manda solleciti di pagamento, dietro ce n'e' sempre uno.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject');
            $table->text('message')->nullable();

            // Quello che abbiamo chiesto, in euro: la somma dei RESIDUI
            // delle sole partite davvero allegate. Non il totale delle
            // fatture (otto fatture su 135 sono pagate a meta') e non lo
            // scaduto intero dell'anagrafica, che comprende anche i riporti
            // di apertura che la mail non nomina.
            $table->decimal('totale_sollecitato', 12, 2);

            // La fotografia: una voce per ogni partita scaduta di quel
            // momento, allegata o no, con il motivo quando no.
            $table->json('partite');

            // I nomi dei PDF partiti davvero. In chiaro e non ricostruiti
            // al volo: il nome del file e' quello che il cliente si trova
            // in mano quando chiede "quale fattura?".
            $table->json('allegati')->nullable();

            $table->string('status', 20);
            $table->text('error_message')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // L'elenco dello scaduto chiede sempre la stessa cosa: per
            // questa anagrafica, quand'e' partito l'ultimo sollecito
            // riuscito.
            $table->index(['tenant_id', 'gestionale_code', 'status', 'created_at'], 'solleciti_pagamento_ultimo_index');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // Chi riceve copia di ogni sollecito di pagamento, come per le
            // altre tredici liste notify_*. Serve perche' una richiesta di
            // soldi senza copia in ufficio e' invisibile: chi risponde al
            // telefono il giorno dopo non sa che gli abbiamo scritto.
            $table->json('notify_sollecito_pagamento_emails')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('notify_sollecito_pagamento_emails');
        });

        Schema::dropIfExists('solleciti_pagamento');
    }
};
