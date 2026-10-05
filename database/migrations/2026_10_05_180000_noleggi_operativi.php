<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Noleggio operativo: la macchina resta di Alex, il cliente paga un canone
 * (05/10/2026).
 *
 * Non e' il noleggio dei preventivi, dove il canone nasce dal coefficiente di
 * Grenke: li' e' il CLIENTE a finanziarsi e ad Alex l'imponibile arriva
 * subito e intero. Qui il capitale e' di Alex, immobilizzato per anni, e il
 * canone deve ricomprarsi la macchina oltre a pagare servizio e detergenti.
 *
 * Si conservano gli INGREDIENTI del calcolo, non solo il canone: fra due anni
 * "perche' 471,71?" deve avere una risposta, e un costo d'acquisto o uno
 * sconto che cambiano non devono riscrivere i contratti gia' firmati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noleggi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            // Macchina e preventivo sono facoltativi: un noleggio si prepara
            // anche prima di sapere quale matricola andra' installata.
            $table->foreignUuid('machine_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->string('descrizione');

            // --- ingredienti del calcolo
            $table->decimal('listino', 12, 2)->default(0);        // base del full service (10% annuo)
            $table->decimal('costo', 12, 2)->default(0);          // quanto costa ad Alex: su questo si recupera
            $table->unsignedSmallInteger('mesi')->default(60);
            $table->decimal('margine', 5, 2)->default(0);         // % sulla quota macchina
            $table->decimal('detergenti_mese', 10, 2)->default(0);
            $table->decimal('ricarico_detergenti', 5, 2)->default(0); // % sui detergenti
            $table->decimal('valore_residuo', 12, 2)->default(0);
            $table->decimal('full_service_percentuale', 5, 2)->default(10);

            // --- risultato, congelato alla firma
            $table->decimal('quota_macchina', 10, 2)->default(0);
            $table->decimal('quota_servizio', 10, 2)->default(0);
            $table->decimal('quota_detergenti', 10, 2)->default(0);
            $table->decimal('canone', 10, 2)->default(0);
            $table->unsignedSmallInteger('mese_pareggio')->nullable();

            $table->date('data_inizio')->nullable();
            $table->string('stato')->default('bozza');
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'stato']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noleggi');
    }
};
