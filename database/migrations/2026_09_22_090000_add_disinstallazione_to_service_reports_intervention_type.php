<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nuovo tipo intervento "disinstallazione" (22/09/2026), accanto a
 * "installazione". Stesso schema della migration della sanificazione: su
 * MySQL l'enum va allargato, altrimenti il valore verrebbe troncato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE service_reports MODIFY intervention_type ENUM(
            'installazione', 'disinstallazione', 'manutenzione_ordinaria', 'manutenzione_straordinaria', 'riparazione', 'garanzia', 'sanificazione'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // I rapportini gia' segnati come disinstallazione tornano
        // installazione, il tipo piu' vicino: senza, l'ALTER fallirebbe.
        DB::table('service_reports')->where('intervention_type', 'disinstallazione')->update(['intervention_type' => 'installazione']);

        DB::statement("ALTER TABLE service_reports MODIFY intervention_type ENUM(
            'installazione', 'manutenzione_ordinaria', 'manutenzione_straordinaria', 'riparazione', 'garanzia', 'sanificazione'
        ) NOT NULL");
    }
};
