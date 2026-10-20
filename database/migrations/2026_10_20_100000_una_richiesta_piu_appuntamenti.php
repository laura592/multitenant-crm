<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gli appuntamenti di una richiesta diventano un elenco.
 *
 * Finora ne stava uno solo: fissarne un altro cancellava il primo, che
 * finiva nel diario come nota. Ma capita spesso di averne due aperti
 * insieme ("giovedi passo io a Calalzo", "lunedi torna il tecnico"), e dal
 * secondo in poi la memoria era una riga di testo (richiesta dell'ufficio,
 * 20/10/2026).
 *
 * Le colonne appointment_at/appointment_notes restano, ma diventano uno
 * specchio del PROSSIMO appuntamento: le usano l'elenco, i colori, la
 * stampa e i promemoria, e continuano a funzionare senza modifiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('information_request_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('information_request_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scheduled_at']);
        });

        // Quelli gia' presi diventano la prima riga dell'elenco.
        $portati = 0;
        foreach (DB::table('information_requests')->whereNotNull('appointment_at')->get() as $r) {
            DB::table('information_request_appointments')->insert([
                'id' => (string) Illuminate\Support\Str::uuid7(),
                'tenant_id' => $r->tenant_id,
                'information_request_id' => $r->id,
                'scheduled_at' => $r->appointment_at,
                'notes' => $r->appointment_notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $portati++;
        }

        echo "  appuntamenti portati nell'elenco: {$portati}\n";
    }

    public function down(): void
    {
        Schema::dropIfExists('information_request_appointments');
    }
};
