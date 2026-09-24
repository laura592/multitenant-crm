<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo: prodotti, listini, materiali e ordini materiali.
 *
 * Una delle otto migration per argomento che hanno sostituito le 158
 * scritte una alla volta (24/09/2026, vedi docs/migrazioni-compattate.md).
 * Ogni tabella si crea solo se non c'e' gia': su un database vivo questa
 * migration non fa niente, su uno nuovo lo costruisce.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['name'], 'brands_name_unique');
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->uuid('parent_id')->nullable();
                $table->string('name');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['parent_id'], 'categories_parent_id_foreign');
                $table->index(['tenant_id'], 'categories_tenant_id_index');
            });
        }

        if (! Schema::hasTable('product_families')) {
            Schema::create('product_families', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('image')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['tenant_id'], 'product_families_tenant_id_index');
            });
        }

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('eureka_article_id')->nullable();
                $table->uuid('tenant_id')->nullable();
                $table->uuid('category_id')->nullable();
                $table->uuid('brand_id')->nullable();
                $table->uuid('product_family_id')->nullable();
                $table->string('sku');
                $table->enum('type', ['machine', 'auxiliary_unit', 'option', 'accessory', 'service']);
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('image')->nullable();
                $table->enum('source', ['franke_ufficiale', 'terzo'])->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unsignedInteger('gestionale_code')->nullable();
                $table->unsignedInteger('gestionale_suggested_code')->nullable();
                $table->string('gestionale_suggested_label')->nullable();
                $table->index(['brand_id'], 'products_brand_id_foreign');
                $table->index(['category_id'], 'products_category_id_foreign');
                $table->index(['eureka_article_id'], 'products_eureka_article_id_index');
                $table->index(['product_family_id'], 'products_product_family_id_foreign');
                $table->unique(['sku'], 'products_sku_unique');
                $table->index(['tenant_id'], 'products_tenant_id_index');
                $table->index(['type'], 'products_type_index');
            });
        }

        if (! Schema::hasTable('product_prices')) {
            Schema::create('product_prices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('product_id');
                $table->decimal('price', 10, 2);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['product_id', 'valid_from', 'valid_to'], 'product_prices_product_id_valid_from_valid_to_index');
            });
        }

        if (! Schema::hasTable('product_option_slots')) {
            Schema::create('product_option_slots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('product_id');
                $table->string('slot_name');
                $table->string('label');
                $table->unsignedInteger('min_qty')->default(0);
                $table->unsignedInteger('max_qty')->nullable();
                $table->boolean('required')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['product_id', 'slot_name'], 'product_option_slots_product_id_slot_name_unique');
            });
        }

        if (! Schema::hasTable('product_option_slot_items')) {
            Schema::create('product_option_slot_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('slot_id');
                $table->uuid('component_product_id');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['component_product_id'], 'product_option_slot_items_component_product_id_foreign');
                $table->unique(['slot_id', 'component_product_id'], 'product_option_slot_items_slot_id_component_product_id_unique');
            });
        }

        if (! Schema::hasTable('product_exclusions')) {
            Schema::create('product_exclusions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('product_id');
                $table->uuid('excludes_product_id');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['excludes_product_id'], 'product_exclusions_excludes_product_id_foreign');
                $table->unique(['product_id', 'excludes_product_id'], 'product_exclusions_unique');
            });
        }

        if (! Schema::hasTable('product_requirements')) {
            Schema::create('product_requirements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('product_id');
                $table->uuid('requires_product_id');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['requires_product_id'], 'product_requirements_requires_product_id_foreign');
                $table->unique(['product_id', 'requires_product_id'], 'product_requirements_unique');
            });
        }

        if (! Schema::hasTable('price_lists')) {
            Schema::create('price_lists', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->uuid('supplier_id')->nullable();
                $table->string('category', 40)->default('listino');
                $table->string('name');
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->string('file_path')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['category'], 'price_lists_category_index');
                $table->index(['supplier_id'], 'price_lists_supplier_id_foreign');
                $table->index(['tenant_id'], 'price_lists_tenant_id_index');
                $table->index(['valid_from', 'valid_to'], 'price_lists_valid_from_valid_to_index');
            });
        }

        if (! Schema::hasTable('materials')) {
            Schema::create('materials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('source')->default('manuale');
                $table->uuid('supplier_id')->nullable();
                $table->string('code');
                $table->unsignedInteger('gestionale_code')->nullable();
                $table->decimal('list_price', 10, 2)->nullable();
                $table->string('category');
                $table->string('type');
                $table->string('maintenance_code')->nullable();
                $table->string('variant')->nullable();
                $table->string('tube_diameter')->nullable();
                $table->string('tube_diameter_2')->nullable();
                $table->string('thread_size')->nullable();
                $table->string('thread_type')->nullable();
                $table->string('barb_diameter')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['category'], 'materials_category_index');
                $table->unique(['code'], 'materials_code_unique');
                $table->index(['gestionale_code'], 'materials_gestionale_code_index');
                $table->index(['supplier_id'], 'materials_supplier_id_foreign');
                $table->index(['tenant_id'], 'materials_tenant_id_index');
            });
        }

        if (! Schema::hasTable('material_orders')) {
            Schema::create('material_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('supplier_id')->nullable();
                $table->string('number')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['supplier_id'], 'material_orders_supplier_id_foreign');
                $table->index(['tenant_id'], 'material_orders_tenant_id_index');
                $table->unique(['tenant_id', 'number'], 'material_orders_tenant_id_number_unique');
            });
        }

        if (! Schema::hasTable('material_order_items')) {
            Schema::create('material_order_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('material_order_id');
                $table->uuid('material_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['material_id'], 'material_order_items_material_id_foreign');
                $table->unique(['material_order_id', 'material_id'], 'material_order_items_material_order_id_material_id_unique');
            });
        }

        if (! self::haChiave('categories', 'categories_parent_id_foreign')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->foreign(['parent_id'], 'categories_parent_id_foreign')->references(['id'])->on('categories')->nullOnDelete();
                $table->foreign(['tenant_id'], 'categories_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('product_families', 'product_families_tenant_id_foreign')) {
            Schema::table('product_families', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'product_families_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('products', 'products_brand_id_foreign')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreign(['brand_id'], 'products_brand_id_foreign')->references(['id'])->on('brands')->nullOnDelete();
                $table->foreign(['category_id'], 'products_category_id_foreign')->references(['id'])->on('categories')->nullOnDelete();
                $table->foreign(['product_family_id'], 'products_product_family_id_foreign')->references(['id'])->on('product_families')->nullOnDelete();
                $table->foreign(['tenant_id'], 'products_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('product_prices', 'product_prices_product_id_foreign')) {
            Schema::table('product_prices', function (Blueprint $table) {
                $table->foreign(['product_id'], 'product_prices_product_id_foreign')->references(['id'])->on('products')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('product_option_slots', 'product_option_slots_product_id_foreign')) {
            Schema::table('product_option_slots', function (Blueprint $table) {
                $table->foreign(['product_id'], 'product_option_slots_product_id_foreign')->references(['id'])->on('products')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('product_option_slot_items', 'product_option_slot_items_component_product_id_foreign')) {
            Schema::table('product_option_slot_items', function (Blueprint $table) {
                $table->foreign(['component_product_id'], 'product_option_slot_items_component_product_id_foreign')->references(['id'])->on('products')->cascadeOnDelete();
                $table->foreign(['slot_id'], 'product_option_slot_items_slot_id_foreign')->references(['id'])->on('product_option_slots')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('product_exclusions', 'product_exclusions_excludes_product_id_foreign')) {
            Schema::table('product_exclusions', function (Blueprint $table) {
                $table->foreign(['excludes_product_id'], 'product_exclusions_excludes_product_id_foreign')->references(['id'])->on('products')->cascadeOnDelete();
                $table->foreign(['product_id'], 'product_exclusions_product_id_foreign')->references(['id'])->on('products')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('product_requirements', 'product_requirements_product_id_foreign')) {
            Schema::table('product_requirements', function (Blueprint $table) {
                $table->foreign(['product_id'], 'product_requirements_product_id_foreign')->references(['id'])->on('products')->cascadeOnDelete();
                $table->foreign(['requires_product_id'], 'product_requirements_requires_product_id_foreign')->references(['id'])->on('products')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('price_lists', 'price_lists_supplier_id_foreign')) {
            Schema::table('price_lists', function (Blueprint $table) {
                $table->foreign(['supplier_id'], 'price_lists_supplier_id_foreign')->references(['id'])->on('suppliers')->nullOnDelete();
                $table->foreign(['tenant_id'], 'price_lists_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('materials', 'materials_supplier_id_foreign')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->foreign(['supplier_id'], 'materials_supplier_id_foreign')->references(['id'])->on('suppliers')->nullOnDelete();
                $table->foreign(['tenant_id'], 'materials_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('material_orders', 'material_orders_supplier_id_foreign')) {
            Schema::table('material_orders', function (Blueprint $table) {
                $table->foreign(['supplier_id'], 'material_orders_supplier_id_foreign')->references(['id'])->on('suppliers')->nullOnDelete();
                $table->foreign(['tenant_id'], 'material_orders_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('material_order_items', 'material_order_items_material_id_foreign')) {
            Schema::table('material_order_items', function (Blueprint $table) {
                $table->foreign(['material_id'], 'material_order_items_material_id_foreign')->references(['id'])->on('materials')->cascadeOnDelete();
                $table->foreign(['material_order_id'], 'material_order_items_material_order_id_foreign')->references(['id'])->on('material_orders')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_order_items');
        Schema::dropIfExists('material_orders');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_requirements');
        Schema::dropIfExists('product_exclusions');
        Schema::dropIfExists('product_option_slot_items');
        Schema::dropIfExists('product_option_slots');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_families');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
