<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le fatture Eureka su cui e' finita la scheda del rapportino, per vedere
 * dall'elenco cosa e' fatturato e cosa no senza aprire i rapportini uno a
 * uno (richiesta dell'ufficio, 21/09/2026).
 *
 * - eureka_fatture: la lista come la restituisce /show/q/sl_fattura, dalla
 *   piu' recente. Una scheda puo' finire su piu' fatture.
 * - eureka_fatturato_il: la data della fattura piu' recente, a parte per
 *   poterci filtrare e ordinare. NULL = non ancora fatturato (o mai
 *   controllato: lo dice la colonna dopo).
 * - eureka_fatture_controllate_il: l'ultima volta che si e' chiesto a
 *   Eureka. Distingue "non ancora fatturato" da "non l'abbiamo mai chiesto".
 *
 * Le riempie eureka:allinea-fatture-rapportini, ogni notte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->json('eureka_fatture')->nullable()->after('eureka_stato_label');
            $table->date('eureka_fatturato_il')->nullable()->after('eureka_fatture');
            $table->timestamp('eureka_fatture_controllate_il')->nullable()->after('eureka_fatturato_il');
            $table->index(['tenant_id', 'eureka_fatturato_il']);
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'eureka_fatturato_il']);
            $table->dropColumn(['eureka_fatture', 'eureka_fatturato_il', 'eureka_fatture_controllate_il']);
        });
    }
};
