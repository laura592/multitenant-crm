<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I documenti che il cliente consegna, attaccati alla sua scheda (29/09/2026).
 *
 * La scheda anagrafica che Alex fa firmare elenca quattro allegati da
 * restituire col modulo: visura camerale o certificato di attribuzione
 * P. IVA, documento d'identita' del firmatario, mandato Ri.Ba./SDD,
 * dichiarazione di esenzione IVA. Fino a oggi quei PDF arrivavano per mail e
 * restavano nella mail: il CRM non aveva nessun posto dove tenerli, e
 * chiunque avesse bisogno della visura doveva andare a cercarla in Outlook.
 *
 * Il caso che l'ha reso urgente: il NATO Stability Policing Centre of
 * Excellence ha mandato certificato di codice fiscale, dichiarazione di
 * esenzione IVA e scheda anagrafica compilata. Sono tre documenti che
 * giustificano come gli si fattura, e senza di loro la fattura non si sa
 * perche' e' non imponibile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id');

            // Le quattro voci del modulo, piu' la scheda stessa e un "altro"
            // per quello che la vita porta.
            $table->string('tipo')->default('altro');
            $table->string('titolo');

            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('dimensione')->nullable();

            // Quando il documento scade (una visura invecchia, un'esenzione
            // puo' essere annuale): nullable perche' la maggior parte non
            // scade, e nessuno deve inventarsi una data per accontentare un
            // campo obbligatorio.
            $table->date('scade_il')->nullable();

            $table->uuid('caricato_da')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
    }
};
