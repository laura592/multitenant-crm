<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->dateTime('clock_in');
            $table->dateTime('clock_out')->nullable();
            $table->enum('source', ['app', 'manuale'])->default('app');
            $table->foreignUuid('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['aperta', 'chiusa', 'corretta'])->default('aperta');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index('clock_in');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['ferie', 'permesso', 'malattia']);
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('hours', 5, 2)->nullable();
            $table->enum('status', ['richiesto', 'approvato', 'rifiutato'])->default('richiesto');
            $table->dateTime('requested_at')->nullable();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('time_entries');
    }
};
