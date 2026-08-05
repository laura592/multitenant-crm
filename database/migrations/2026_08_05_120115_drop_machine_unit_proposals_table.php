<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GestionaleSyncRunner::proposeInstalledMachines() ora crea direttamente
 * MachineUnit (source='eureka') invece di lasciarle in coda di conferma —
 * la tabella delle proposte non serve piu'. Le 444 righe gia' in coda
 * vengono naturalmente rilavorate e trasformate in MachineUnit veri al
 * prossimo giro di gestionale:sync (il dedup ora guarda solo
 * MachineUnit.serial_number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('machine_unit_proposals');
    }

    public function down(): void
    {
        Schema::create('machine_unit_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number');
            $table->string('model_name')->nullable();
            $table->unsignedInteger('eureka_article_id');
            $table->string('eureka_article_code')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial_number']);
        });
    }
};
