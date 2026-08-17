<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ServiceReport::TYPE_SANIFICAZIONE esisteva gia' lato PHP (usato da tempo in
 * ServiceReportResource) ma l'enum a livello DB non era mai stato ampliato di
 * conseguenza: salvare un rapportino di sanificazione troncava silenziosamente
 * il valore (MySQL warning 1265, "Data truncated for column
 * 'intervention_type'") invece di fallire in modo esplicito.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ENUM nativo esiste solo su MySQL: su sqlite (test) la colonna e'
        // gia' testuale, TYPE_SANIFICAZIONE ci entra senza bisogno di ALTER.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE service_reports MODIFY intervention_type ENUM(
            'installazione', 'manutenzione_ordinaria', 'manutenzione_straordinaria', 'riparazione', 'garanzia', 'sanificazione'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE service_reports MODIFY intervention_type ENUM(
            'installazione', 'manutenzione_ordinaria', 'manutenzione_straordinaria', 'riparazione', 'garanzia'
        ) NOT NULL");
    }
};
