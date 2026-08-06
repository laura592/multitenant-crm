<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La manutenzione si fa sui macchinari (MachineUnit), non solo su
        // quelli in comodato: il comodato resta un'area a se', usata solo
        // per i calcoli di costo (vedi ComodatoMacchinaResource). Nessun
        // piano di manutenzione in produzione ha comodato_macchina_id
        // valorizzato, quindi non serve backfill.
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comodato_macchina_id');
        });

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->foreignUuid('machine_unit_id')->nullable()->after('customer_id')
                ->constrained('machine_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_unit_id');
        });

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->foreignUuid('comodato_macchina_id')->nullable()->after('customer_id')
                ->constrained('comodato_macchine')->nullOnDelete();
        });
    }
};
