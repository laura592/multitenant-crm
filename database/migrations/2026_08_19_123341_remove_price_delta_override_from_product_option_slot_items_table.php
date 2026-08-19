<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_option_slot_items', function (Blueprint $table) {
            $table->dropColumn('price_delta_override');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_option_slot_items', function (Blueprint $table) {
            $table->decimal('price_delta_override', 10, 2)->nullable();
        });
    }
};
