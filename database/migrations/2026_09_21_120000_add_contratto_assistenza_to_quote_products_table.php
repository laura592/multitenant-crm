<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il contratto di assistenza scelto per una macchina del preventivo
 * ('full' | 'easy'), sulla riga della macchina: un preventivo puo' avere piu'
 * macchine, e ognuna il suo contratto.
 *
 * Il canone non si salva. Si ricalcola dalle righe (macchina e opzioni, a
 * prezzo di listino) con App\Support\Assistenza\ContrattoAssistenza, cosi'
 * se si cambia un'opzione il canone la segue da solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_products', function (Blueprint $table) {
            $table->string('contratto_assistenza', 10)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('quote_products', function (Blueprint $table) {
            $table->dropColumn('contratto_assistenza');
        });
    }
};
