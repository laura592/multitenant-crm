<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo spostamento di una macchina che Eureka conosce e il CRM no.
 *
 * Eureka non chiude la consegna vecchia quando una macchina va da un altro
 * cliente: in art_installati la matricola compare presso entrambi, ognuno
 * con la sua bolla. Il sync propone la posizione della bolla piu' recente;
 * una persona conferma (22/09/2026, matricole 1863540, 1438814...).
 *
 * spostamento_scartato ricorda la proposta rifiutata (cliente|data), cosi'
 * non ricompare a ogni sync finche' Eureka non dice qualcosa di nuovo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->foreignUuid('spostamento_suggerito_customer_id')->nullable()->after('fusione_suggerita_motivo')
                ->constrained('customers')->nullOnDelete();
            $table->date('spostamento_suggerito_il')->nullable()->after('spostamento_suggerito_customer_id');
            $table->string('spostamento_suggerito_motivo')->nullable()->after('spostamento_suggerito_il');
            $table->string('spostamento_scartato')->nullable()->after('spostamento_suggerito_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spostamento_suggerito_customer_id');
            $table->dropColumn(['spostamento_suggerito_il', 'spostamento_suggerito_motivo', 'spostamento_scartato']);
        });
    }
};
