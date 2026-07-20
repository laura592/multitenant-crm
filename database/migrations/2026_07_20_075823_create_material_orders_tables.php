<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ordine materiali salvato dal tecnico/tenant (non condiviso, a
        // differenza del catalogo Material): può essere ripreso e modificato
        // prima di rigenerare il PDF da inviare al fornitore.
        Schema::create('material_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('material_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('material_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('material_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['material_order_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_order_items');
        Schema::dropIfExists('material_orders');
    }
};
