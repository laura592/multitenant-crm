<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Congela il pagante sul rapportino.
 *
 * Finora invoiceRecipient() lo ricalcolava a ogni lettura, risalendo alla
 * macchina o al cliente. Comodo finche' il pagante non cambia mai — ma
 * quando cambia, cambia anche cosa mostrano i rapportini di due anni fa, e
 * lo storico di chi ha pagato cosa si riscrive da solo.
 *
 * Da qui in poi il pagante viene fissato sul documento. Il backfill riempie
 * quello che si puo' ricostruire davvero:
 *
 * 1. dallo snapshot Eureka gia' presente (eureka_destinazione_code), che e'
 *    il pagante al momento dell'intervento — la fonte piu' fedele;
 * 2. per i rapportini senza snapshot resta NULL, e invoiceRecipient()
 *    continua a ricalcolare come prima. Inventare un valore sarebbe peggio
 *    che ammettere di non saperlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->foreignUuid('billing_customer_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customers')
                ->nullOnDelete();
        });

        // Backfill dallo snapshot Eureka: il codice destinazione e' il
        // gestionale_code del cliente a cui il documento e' stato fatturato.
        //
        // Sottoquery e non UPDATE...JOIN: quella sintassi e' di MySQL, e le
        // migrazioni girano anche su SQLite (test in memoria).
        DB::table('service_reports')
            ->whereNotNull('eureka_destinazione_code')
            ->whereNull('billing_customer_id')
            ->update([
                'billing_customer_id' => DB::raw('(
                    SELECT c.id FROM customers c
                    WHERE c.gestionale_code = service_reports.eureka_destinazione_code
                      AND c.tenant_id = service_reports.tenant_id
                      AND c.deleted_at IS NULL
                    LIMIT 1
                )'),
            ]);
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_customer_id');
        });
    }
};
