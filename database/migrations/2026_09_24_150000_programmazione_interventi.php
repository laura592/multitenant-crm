<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il giro programmato: l'ufficio prepara, il tecnico legge (24/09/2026).
 *
 * Fino a oggi la programmazione viveva su un foglio a quadretti con la carta
 * intestata: "28/09 chiusura Barricata + Isamar", "05/10 Garden ⇒ pomeriggio",
 * barrato quando fatto. Chi lo teneva era l'ufficio, e i tecnici lo sapevano
 * per telefono.
 *
 * Il CRM aveva solo le due estremita': il piano, che dice ogni quanto si
 * torna (MaintenanceSchedule::next_due_date, una scadenza CALCOLATA), e il
 * rapportino, che dice cosa e' stato fatto. In mezzo, il lavoro deciso da
 * qualcuno per un giorno preciso, non c'era.
 *
 * Qui ci sta. Un intervento programmato e' un impegno: giorno, mezza
 * giornata, cliente, che cosa, e chi ci va. Quando e' fatto si collega al
 * rapportino che lo documenta, e da li' in poi la storia la racconta quello.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interventi_programmati', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            $table->date('data');
            // Il foglio scrive "⇒ pomeriggio" solo quando conta: di norma il
            // giro e' della giornata e l'ordine lo decide il tecnico.
            $table->string('momento')->default('giornata');

            // Nullable: sul foglio c'e' anche "scrivere agenzia per rapportino
            // sparito", che e' un impegno dell'ufficio e non ha un cliente.
            $table->uuid('customer_id')->nullable();
            $table->uuid('machine_unit_id')->nullable();
            // Il piano che questo intervento serve: una chiusura lo mette in
            // pausa, un'apertura lo risveglia.
            $table->uuid('maintenance_schedule_id')->nullable();

            // Vuoto = ancora da assegnare. E' la colonna su cui l'ufficio
            // lavora quando prepara la settimana.
            $table->uuid('technician_id')->nullable();

            $table->string('tipo')->default('altro');
            $table->string('titolo')->nullable();
            $table->text('note')->nullable();

            $table->string('stato')->default('da_fare');
            $table->timestamp('fatto_il')->nullable();
            // Il rapportino che lo ha chiuso: da qui in poi la storia e' la sua.
            $table->uuid('service_report_id')->nullable();

            $table->timestamps();

            // Le due letture vere: "il giro del giorno" e "il mio giro".
            $table->index(['tenant_id', 'data', 'momento'], 'programmati_giorno_index');
            $table->index(['tenant_id', 'technician_id', 'data'], 'programmati_tecnico_index');
            $table->index(['tenant_id', 'stato', 'data'], 'programmati_stato_index');
            $table->index(['customer_id'], 'programmati_customer_index');
            $table->index(['service_report_id'], 'programmati_service_report_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventi_programmati');
    }
};
