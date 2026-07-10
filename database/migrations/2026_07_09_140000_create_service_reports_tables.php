<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('number');
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('comodato_macchina_id')->nullable()->constrained('comodato_macchine')->nullOnDelete();
            $table->foreignUuid('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('machine_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('machine_serial_number')->nullable();
            $table->foreignUuid('technician_id')->constrained('users')->restrictOnDelete();

            $table->enum('intervention_type', [
                'installazione', 'manutenzione_ordinaria', 'manutenzione_straordinaria', 'riparazione', 'garanzia',
            ]);
            $table->date('intervention_date');
            $table->dateTime('arrival_at')->nullable();
            $table->dateTime('departure_at')->nullable();
            $table->text('problem_description')->nullable();
            $table->text('work_performed')->nullable();
            $table->enum('status', ['bozza', 'completato', 'firmato', 'inviato'])->default('bozza');
            $table->string('customer_signature_path')->nullable();
            $table->string('technician_signature_path')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index('customer_id');
            $table->index('technician_id');
            $table->index('intervention_type');
        });

        Schema::create('service_report_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_report_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_cost_snapshot', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('service_report_id');
        });

        Schema::create('service_report_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_report_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject');
            $table->text('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('service_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_report_emails');
        Schema::dropIfExists('service_report_products');
        Schema::dropIfExists('service_reports');
    }
};
