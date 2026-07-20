<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('technician_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('comodato_macchina_id')->nullable()->constrained('comodato_macchine')->nullOnDelete();
            $table->foreignUuid('deadline_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('service_report_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['pianificato', 'confermato', 'in_corso', 'completato', 'annullato'])->default('pianificato');
            $table->text('notes')->nullable();

            $table->string('google_event_id')->nullable();
            $table->dateTime('google_synced_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
            $table->index('technician_id');
            $table->index('google_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};