<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le letture delle casette dell'acqua (30/10/2026).
 *
 * Due casette comunali -- Cessalto e Casale sul Sile -- e un lavoro nuovo per
 * i tecnici: a ogni passaggio si leggono tre contatori (acqua erogata,
 * incasso, venduto), si spunta che cosa si e' fatto e si fotografano i
 * display. Fino a oggi quei numeri vivevano in un foglio di calcolo per
 * casetta e le foto nel telefono di chi era andato.
 *
 * Non e' un dettaglio di forma. Le foto arrivate il 30/10/2026 erano tre
 * JPEG con nome a caso, senza data negli EXIF (il passaggio da WhatsApp la
 * cancella) e senza posizione: tre numeri senza indirizzo, attribuibili solo
 * a memoria. E i fogli, con le formule che sottraevano celle vuote, davano
 * "Totale mese -1.052.210" su Cessalto. Qui la lettura nasce attaccata alla
 * casetta, al giorno e al tecnico, e il consumo del periodo si calcola dalla
 * lettura precedente -- se non c'e', resta vuoto invece di valere il
 * contatore intero.
 *
 * Non confondere con il lavaggio (Lavaggio) ne' con il rapportino
 * (ServiceReport): un passaggio sulla casetta puo' generare anche un
 * rapportino, e in quel caso i due si collegano, ma la lettura resta una
 * cosa sua perche' serve al rendiconto che il Comune riceve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letture_casette', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // La casetta e' una MachineUnit di tipo impianto_acqua: ha gia'
            // matricola, cliente che la ospita e storico degli spostamenti.
            $table->uuid('machine_unit_id');
            // Copia di chi ospitava la casetta il giorno della lettura: il
            // rendiconto si intesta al Comune, e se un giorno la casetta
            // cambia mano le letture vecchie devono restare del Comune
            // vecchio.
            $table->uuid('customer_id')->nullable();
            // Se quel passaggio ha prodotto anche un rapportino.
            $table->uuid('service_report_id')->nullable();

            $table->date('data');
            $table->uuid('rilevata_da')->nullable();

            // I tre contatori, nell'ordine in cui il tecnico li legge sul
            // posto. L'unita' dell'acqua la dice la casetta
            // (machine_units.unita_contatore): Casale conta metri cubi,
            // Cessalto litri, e sommare le due cose non vuol dire niente.
            $table->decimal('contatore_acqua', 14, 2)->nullable();
            $table->decimal('incasso_totale', 12, 2)->nullable();
            $table->decimal('valore_vendite', 12, 2)->nullable();

            // Le quattro operazioni del rendiconto (A, B, C, D sui fogli).
            $table->boolean('controllo_pulizia')->default(false);
            $table->boolean('sanificazione')->default(false);
            $table->boolean('ricarica_co2')->default(false);
            $table->boolean('sostituzione_filtro')->default(false);

            // Le foto scattate davanti alla casetta. Tre campi con un nome,
            // non un mucchio di allegati: una foto di un display vale solo
            // se si sa quale numero documenta.
            $table->string('foto_contatore')->nullable();
            $table->string('foto_incasso')->nullable();
            $table->string('foto_vendite')->nullable();
            $table->json('foto_altre')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            // La lettura si cerca sempre per casetta e data: e' anche l'ordine
            // in cui si trova la precedente per calcolare il consumo.
            $table->index(['tenant_id', 'machine_unit_id', 'data'], 'letture_casette_casetta_data_index');
            $table->index(['tenant_id', 'data'], 'letture_casette_data_index');
            $table->index(['customer_id'], 'letture_casette_customer_index');
            $table->index(['service_report_id'], 'letture_casette_service_report_index');
        });

        Schema::table('machine_units', function (Blueprint $table) {
            // Solo per le casette: in che unita' conta il contatore e dopo
            // quanta acqua va cambiato il filtro a carboni attivi. La soglia
            // sul foglio di Casale era scritta a mano in un angolo ("PROX
            // CAMBIO FILTRO 467 M3"), cioe' viveva nella memoria di chi aveva
            // tenuto il foglio.
            $table->string('unita_contatore')->nullable()->after('type');
            $table->decimal('filtro_ogni', 14, 2)->nullable()->after('unita_contatore');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letture_casette');

        Schema::table('machine_units', fn (Blueprint $table) => $table->dropColumn(['unita_contatore', 'filtro_ogni']));
    }
};
