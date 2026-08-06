<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Il vino non ha piu' una cadenza standard (vedi
        // MaintenanceSchedule::STANDARD_FREQUENCY_DAYS): i piani esistenti
        // tornano "a chiamata", senza cadenza ne' scadenza automatica.
        DB::table('maintenance_schedules')
            ->where('type', 'lavaggio')
            ->where('beverage_type', 'vino')
            ->update([
                'frequency_days' => null,
                'next_due_date' => null,
            ]);
    }

    public function down(): void
    {
        // Non e' possibile recuperare la cadenza/scadenza precedente.
    }
};
