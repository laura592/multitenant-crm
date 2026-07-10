<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plate');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('deadlines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('deadlinable');

            $table->enum('type', [
                'assicurazione', 'revisione', 'polizza_rct', 'manutenzione_ordinaria', 'licenza', 'contratto', 'altro',
            ]);
            $table->date('due_date');
            $table->unsignedInteger('reminder_days_before')->default(30);
            $table->enum('status', ['attiva', 'scaduta', 'rinnovata'])->default('attiva');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'due_date']);
            $table->index('type');
        });

        Schema::create('maintenance_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('comodato_macchina_id')->nullable()->constrained('comodato_macchine')->nullOnDelete();

            $table->enum('frequency', ['mensile', 'trimestrale', 'semestrale', 'annuale']);
            $table->foreignUuid('last_service_report_id')->nullable()->constrained('service_reports')->nullOnDelete();
            $table->date('next_due_date');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('deadlines');
        Schema::dropIfExists('vehicles');
    }
};
