<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aggiunge lo stato "in_gestionale" ai rapportini.
 *
 * `status` e' una ENUM, quindi un valore nuovo va dichiarato qui o MySQL lo
 * tronca in silenzio ("Data truncated for column 'status'").
 *
 * Serve a distinguere due cose che finora si confondevano: "inviato" vuol
 * dire mandato AL CLIENTE (PDF/email) e ce l'hanno quasi 2.900 rapportini;
 * "in_gestionale" vuol dire passato in Eureka, ed e' lo stato da cui il
 * documento non si tocca piu' dal CRM.
 *
 * Lo scrive SendServiceReportToGestionaleJob quando Eureka accetta il
 * documento; non e' scegliibile a mano dal form.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ENUM esiste solo su MySQL: su SQLite (test in memoria) la colonna
        // e' gia' un testo libero e accetta il valore nuovo senza modifiche.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE service_reports
            MODIFY COLUMN status
            ENUM('bozza','completato','firmato','inviato','in_gestionale','rifiutato')
            NOT NULL DEFAULT 'bozza'
        ");
    }

    public function down(): void
    {
        // I rapportini gia' passati in Eureka tornano "completato": e' lo
        // stato piu' vicino, e non si perde il fatto che siano chiusi.
        DB::table('service_reports')->where('status', 'in_gestionale')->update(['status' => 'completato']);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE service_reports
            MODIFY COLUMN status
            ENUM('bozza','completato','firmato','inviato','rifiutato')
            NOT NULL DEFAULT 'bozza'
        ");
    }
};
