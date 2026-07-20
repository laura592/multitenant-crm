<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            // riusa i nomi noti di App\Filament\Actions\ConfigureMachineAction::KNOWN_GROUPS
            // (cooling_unit, grinder, powder, steam, addon, color, power, license),
            // 'altro' per qualunque slot non ancora categorizzato
            $table->string('slot_name');
            $table->string('label');
            $table->unsignedInteger('min_qty')->default(0);
            $table->unsignedInteger('max_qty')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'slot_name']);
        });

        Schema::create('product_option_slot_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('slot_id')->constrained('product_option_slots')->cascadeOnDelete();
            $table->foreignUuid('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('price_delta_override', 10, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['slot_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_slot_items');
        Schema::dropIfExists('product_option_slots');
    }
};
