<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il modulo comodato (calcoli di costo macchina/ammortamento/margine) viene
 * rimosso: la manutenzione si fa sui macchinari (MachineUnit,
 * maintenance_schedules.machine_unit_id), il comodato non aveva altro uso
 * agganciato al resto dell'app. service_reports.comodato_macchina_id non era
 * un campo del form (solo lettura in infolist/PDF), quindi nessun dato
 * operativo si perde smettendo di mostrarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comodato_macchina_id');
        });

        Schema::dropIfExists('comodato_macchine');
    }

    public function down(): void
    {
        Schema::create('comodato_macchine', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();

            $table->string('nome_macchina');
            $table->decimal('costo_macchina', 10, 2);
            $table->decimal('costo_attrezzatura', 10, 2)->default(0);
            $table->unsignedInteger('anni_ammortamento');
            $table->decimal('prezzo_annuale_consumabili', 10, 2)->default(0);
            $table->decimal('costi_manutenzione_annui', 10, 2)->default(0);
            $table->decimal('costo_caffe_per_battitura', 10, 4)->default(0);
            $table->unsignedInteger('erogazioni_annuali_minime')->nullable();
            $table->unsignedInteger('erogazioni_previste_annue')->nullable();
            $table->decimal('canone_fisso_annuale', 10, 2)->default(0);
            $table->decimal('margine_percentuale', 5, 2)->default(0);
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::table('service_reports', function (Blueprint $table) {
            $table->foreignUuid('comodato_macchina_id')->nullable()->after('customer_id')
                ->constrained('comodato_macchine')->nullOnDelete();
        });
    }
};
