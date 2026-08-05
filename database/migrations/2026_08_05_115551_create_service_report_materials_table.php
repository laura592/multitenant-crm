<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot NUOVO invece di riusare/alterare service_report_products: i
 * rapportini gia' compilati hanno ricambi reali salvati come Product —
 * cambiare la relazione esistente li farebbe sparire dalle viste/PDF
 * storici. Il vecchio pivot resta intatto, solo i rapportini nuovi usano
 * questo (via ServiceReport::materialsUsed()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_report_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_report_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('material_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_cost_snapshot', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('service_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_report_materials');
    }
};
