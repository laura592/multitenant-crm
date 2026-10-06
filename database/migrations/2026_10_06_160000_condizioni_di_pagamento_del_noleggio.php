<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condizioni di pagamento del noleggio (06/10/2026).
 *
 * Il contratto diceva quanto si paga ma non quando ne' come: un contratto
 * senza condizioni di pagamento non si firma, e soprattutto non si incassa --
 * su sessanta canoni la differenza fra "30 giorni data fattura" e "60 giorni
 * fine mese" sono due mesi di canone sempre scoperti.
 *
 * Sono campi e non testo fisso perche' cambiano da cliente a cliente: un ente
 * pubblico non paga con le stesse condizioni di un bar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->string('periodicita_fatturazione')->default('mensile')->after('data_inizio');
            $table->string('modalita_pagamento')->default('bonifico')->after('periodicita_fatturazione');
            $table->string('termini_pagamento')->default('30_df')->after('modalita_pagamento');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn(['periodicita_fatturazione', 'modalita_pagamento', 'termini_pagamento']);
        });
    }
};
