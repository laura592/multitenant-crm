<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un mezzo che non c'e' piu' non va in regola, e un documento che non esiste
 * non e' un documento mancante (24/11/2026).
 *
 * Il controllo sui mezzi scoperti (Vehicle::scopeConScadenzeMancanti, nato il
 * 23/11/2026) segnalava cinque mezzi su sette. Due erano falsi:
 *
 * - FC985CF, il Transporter, e' stato VENDUTO il 23/10/2026 e sostituito dal
 *   Vito HG747AP. Che sia uscito dalla flotta era scritto nelle note in
 *   lettere maiuscole, dove nessuna query lo puo' leggere: per il database
 *   era un mezzo come gli altri, con diciotto scadenze tutte "rinnovata" e
 *   quindi nessuna attiva.
 * - HG747AP, il Vito nuovo, non ha il bollo perche' e' compreso nel canone
 *   di leasing Mercedes-Benz Financial. Non e' una dimenticanza: e' una
 *   scadenza che per questo mezzo non esiste, e continuare a chiederla
 *   vuol dire insegnare a chi legge che l'avviso si puo' ignorare.
 *
 * Da qui le due colonne e la tabella delle esenzioni. L'esenzione e' una
 * riga con un motivo scritto e non un flag booleano: fra un anno la domanda
 * non sara' "e' esente?" ma "perche'?", e la risposta deve stare accanto al
 * dato, non nella memoria di qualcuno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Quando il mezzo e' uscito dalla flotta (venduto, rottamato,
            // riconsegnato a fine leasing). Nullable: quasi tutti i mezzi
            // sono in flotta, e un default non direbbe niente di vero.
            $table->date('dismesso_il')->nullable()->after('year');

            // Serve a calcolare la revisione quando non la si ha sotto mano:
            // per il Vito la prima revisione si conta dall'immatricolazione,
            // il 21/10/2026, non da una data trovata su un bollettino.
            $table->date('immatricolato_il')->nullable()->after('dismesso_il');
        });

        Schema::create('vehicle_deadline_exemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // Stessi valori di deadlines.type, ma volutamente non un enum
            // replicato: qui interessano solo i tipi obbligatori
            // (Vehicle::TIPI_OBBLIGATORI) e un enum in due posti diversi si
            // disallinea alla prima aggiunta.
            $table->string('type');
            $table->string('motivo');
            $table->timestamps();

            // Un mezzo non puo' essere esente due volte dalla stessa cosa.
            $table->unique(['vehicle_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_deadline_exemptions');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['dismesso_il', 'immatricolato_il']);
        });
    }
};
