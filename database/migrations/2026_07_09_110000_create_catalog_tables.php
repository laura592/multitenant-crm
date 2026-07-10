<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('product_families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

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

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL = catalogo condiviso (listino ufficiale Franke), valorizzato = riservato
            // al tenant master oppure prodotto privato di un partner (§4.2, §11.2)
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_family_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku')->unique();
            $table->enum('type', ['machine', 'auxiliary_unit', 'option', 'accessory', 'service']);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            // per la garanzia (art. 11.3): distingue ricambi/opzioni originali Franke da terzi
            $table->enum('source', ['franke_ufficiale', 'terzo'])->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('type');
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'valid_from', 'valid_to']);
        });

        Schema::create('product_compatibilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('base_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('option_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('option_group_id')->constrained('product_option_groups')->cascadeOnDelete();
            // required = l'opzione/unità ausiliaria è obbligatoria per questa variante base
            $table->enum('constraint_type', ['compatible', 'required'])->default('compatible');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['base_product_id', 'option_product_id'], 'product_compat_base_option_unique');
            $table->index('option_product_id');
        });

        Schema::create('product_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('requires_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'requires_product_id'], 'product_requirements_unique');
        });

        Schema::create('product_exclusions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('excludes_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'excludes_product_id'], 'product_exclusions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_exclusions');
        Schema::dropIfExists('product_requirements');
        Schema::dropIfExists('product_compatibilities');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('product_families');
        Schema::dropIfExists('categories');
    }
};
