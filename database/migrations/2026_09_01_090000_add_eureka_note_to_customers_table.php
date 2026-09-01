<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Copia in sola lettura del campo note dell'anagrafica Eureka
            // (F15NOTE.NOTE), esposto dall'API dal 2026-08-27. Su 2041
            // anagrafiche ne risultano valorizzate 101, e il contenuto e'
            // quasi sempre il soggetto che paga: "PAGA RIVER CAFFE' TREVISO",
            // "MARTELLOZZO", "LAVAGGI MARTELLOZZO" — cioe' proprio
            // l'informazione che il CRM modella con billing_customer_id e
            // MachineUnit::eureka_billing_customer_code (vedi
            // ServiceReport::invoiceRecipient()).
            //
            // Colonna DEDICATA e non riuso di gestionale_review_note: quella
            // e' scritta da chi rivede l'anagrafica nel CRM, questa la
            // sovrascrive il sync a ogni giro. Tenerle separate e' l'unico
            // modo perche' un aggiornamento da Eureka non cancelli mai un
            // testo scritto da una persona.
            //
            // Volutamente NON collegata alla logica di fatturazione: e' testo
            // libero, va letta da una persona. Serve come riscontro contro il
            // pagante configurato, non come sua fonte.
            $table->text('eureka_note')->nullable()->after('gestionale_code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('eureka_note');
        });
    }
};
