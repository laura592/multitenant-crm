<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Alcuni clienti (es. un campeggio con una macchina di un gestore in
        // comodato) vengono fatturati da un altro cliente invece che da se
        // stessi: preventivi/rapportini/lavaggi restano registrati sul
        // cliente dove si lavora davvero (customer_id invariato ovunque),
        // ma se billing_customer_id e' valorizzato e' quel cliente a comparire
        // come destinatario di documenti/fatture (segnalato: "Martellozzo
        // paga per il campeggio perche' ci ha messo la macchina in comodato,
        // ma Alex fa i lavaggi al campeggio per conto di Martellozzo").
        // Self-reference nullable, non un nuovo modello: i due clienti sono
        // gia' entrambi normali Customer, con la propria intera anagrafica/
        // storico gia' esistente in molti casi (vedi dati legacy con nomi
        // tipo "Bar Joker C/O Campeggio Joker").
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUuid('billing_customer_id')->nullable()->after('id')->constrained('customers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_customer_id');
        });
    }
};
