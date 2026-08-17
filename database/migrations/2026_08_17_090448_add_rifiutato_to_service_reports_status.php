<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ENUM nativo esiste solo su MySQL: su sqlite (test) la colonna e'
        // gia' testuale, 'rifiutato' ci entra senza bisogno di ALTER.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE service_reports MODIFY status ENUM(
            'bozza', 'completato', 'firmato', 'inviato', 'rifiutato'
        ) NOT NULL DEFAULT 'bozza'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE service_reports MODIFY status ENUM(
            'bozza', 'completato', 'firmato', 'inviato'
        ) NOT NULL DEFAULT 'bozza'");
    }
};
