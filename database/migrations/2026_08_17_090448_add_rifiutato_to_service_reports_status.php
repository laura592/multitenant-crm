<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE service_reports MODIFY status ENUM(
            'bozza', 'completato', 'firmato', 'inviato', 'rifiutato'
        ) NOT NULL DEFAULT 'bozza'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE service_reports MODIFY status ENUM(
            'bozza', 'completato', 'firmato', 'inviato'
        ) NOT NULL DEFAULT 'bozza'");
    }
};
