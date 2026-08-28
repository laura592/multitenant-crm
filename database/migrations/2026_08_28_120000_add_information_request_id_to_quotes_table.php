<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Da dove nasce un preventivo: la richiesta informazioni che l'ha
     * originato. Sta sul preventivo e non sulla richiesta perche' e'
     * uno-a-molti — da una richiesta possono uscire piu' preventivi
     * (varianti, rilanci), e se piu' preventivi finiscono nello stesso
     * QuoteGroup l'offerta si legge da li' senza doverla collegare a parte.
     *
     * Il contrario (il collegamento sull'offerta) obbligherebbe a creare un
     * gruppo anche per un preventivo singolo: oggi 30 preventivi su 57 non
     * stanno in nessun gruppo.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignUuid('information_request_id')
                ->nullable()
                ->after('quote_group_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('information_request_id');
        });
    }
};
