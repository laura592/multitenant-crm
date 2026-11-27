<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il registro di quando abbiamo chiesto una risposta su un preventivo.
 *
 * Finora i giorni di silenzio si contavano dall'ultima mail partita dal
 * CRM, e basta. Il 27/11/2026 Laura ha telefonato al NATO Stability
 * Policing Centre -- stanno ancora valutando -- e il CRM non ne sapeva
 * niente: il giorno dopo quella trattativa sarebbe tornata in cima
 * all'elenco di chi richiamare, ferma da 67 giorni, come se nessuno
 * l'avesse mai sentita.
 *
 * Qui dentro finisce ogni volta che si e' chiesta una risposta, in
 * qualunque modo: la mail di sollecito e la telefonata. Da qui si contano
 * due cose:
 *
 *  - DA QUANDO TACE: il silenzio riparte dall'ultimo contatto vero, non
 *    dall'ultima mail;
 *  - QUANTE VOLTE ABBIAMO CHIESTO: dopo due solleciti senza risposta la
 *    trattativa esce dall'elenco e li' finisce (indicazione di Laura:
 *    "non posso tormentarli per sempre"). Lo stato resta "scaduto": non
 *    si inventa un rifiuto che il cliente non ha mai detto.
 *
 * Il contatto si attacca alla TRATTATIVA, non al preventivo: su un'offerta
 * con sei alternative si telefona una volta sola. Percio' una delle due
 * colonne e' piena e l'altra no, mai tutte e due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_contatti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Una delle due, mai entrambe: vedi il commento di classe.
            $table->foreignUuid('quote_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('quote_group_id')->nullable()->constrained()->cascadeOnDelete();

            // Chi l'ha fatto. Nullable perche' un contatto registrato da un
            // comando non ha un utente dietro.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('tipo', 20);
            $table->timestamp('data');
            $table->text('nota')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // L'elenco "da sollecitare" chiede sempre la stessa cosa: per
            // questa trattativa, qual e' l'ultimo contatto e quanti
            // solleciti sono partiti.
            $table->index(['quote_id', 'tipo', 'data']);
            $table->index(['quote_group_id', 'tipo', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_contatti');
    }
};
