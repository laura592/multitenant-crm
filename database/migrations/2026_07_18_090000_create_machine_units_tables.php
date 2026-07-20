<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('current_customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('serial_number');
            $table->string('model_name')->nullable();
            $table->string('owner_name');
            $table->enum('status', ['in_magazzino', 'installata', 'rimossa'])->default('in_magazzino');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'serial_number']);
            $table->index('current_customer_id');
        });

        Schema::create('machine_unit_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('machine_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('placed_at');
            $table->dateTime('removed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['machine_unit_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_unit_placements');
        Schema::dropIfExists('machine_units');
    }
};
