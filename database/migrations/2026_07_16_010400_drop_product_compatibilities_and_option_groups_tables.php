<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Porta ogni compatibilita' esistente sul nuovo modello a slot prima
        // di eliminare le vecchie tabelle (vedi
        // App\Console\Commands\MigrateCompatibilitiesToSlots).
        Artisan::call('products:migrate-compatibilities-to-slots');

        Schema::dropIfExists('product_compatibilities');
        Schema::dropIfExists('product_option_groups');
    }

    public function down(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('label');
            $table->enum('selection_type', ['single', 'multiple'])->default('multiple');
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('product_compatibilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('base_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('option_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('option_group_id')->constrained('product_option_groups')->cascadeOnDelete();
            $table->enum('constraint_type', ['compatible', 'required'])->default('compatible');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['base_product_id', 'option_product_id'], 'product_compat_base_option_unique');
            $table->index('option_product_id');
        });
    }
};
