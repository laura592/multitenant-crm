<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafiche: clienti, fornitori, pagamenti, mezzi.
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
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('billing_customer_id')->nullable();
                $table->uuid('tenant_id');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('company_name')->nullable();
                $table->string('street')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->json('emails')->nullable();
                $table->json('phones')->nullable();
                $table->string('tax_code')->nullable();
                $table->string('vat_number')->nullable();
                $table->string('sdi')->nullable();
                $table->string('pec')->nullable();
                $table->string('website')->nullable();
                $table->timestamp('website_checked_at')->nullable();
                $table->string('source')->default('app');
                $table->timestamp('consent_privacy_at')->nullable();
                $table->timestamp('consent_marketing_at')->nullable();
                $table->string('consent_source')->nullable();
                $table->unsignedInteger('gestionale_code')->nullable();
                $table->text('eureka_note')->nullable();
                $table->timestamp('approved_for_gestionale_at')->nullable();
                $table->timestamp('sent_to_gestionale_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('gestionale_review_flagged_at')->nullable();
                $table->text('gestionale_review_note')->nullable();
                $table->unsignedInteger('gestionale_suggested_code')->nullable();
                $table->string('gestionale_suggested_label')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['billing_customer_id'], 'customers_billing_customer_id_foreign');
                $table->unique(['gestionale_code'], 'customers_gestionale_code_unique');
                $table->index(['source'], 'customers_source_index');
                $table->index(['tenant_id'], 'customers_tenant_id_index');
            });
        }

        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('name');
                $table->string('address')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['tenant_id'], 'suppliers_tenant_id_index');
            });
        }

        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('slug');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['slug'], 'payment_methods_slug_unique');
            });
        }

        if (! Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('plate');
                $table->string('brand')->nullable();
                $table->string('model')->nullable();
                $table->unsignedSmallInteger('year')->nullable();
                $table->uuid('assigned_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['assigned_user_id'], 'vehicles_assigned_user_id_foreign');
                $table->index(['tenant_id'], 'vehicles_tenant_id_index');
            });
        }

        if (! self::haChiave('customers', 'customers_billing_customer_id_foreign')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreign(['billing_customer_id'], 'customers_billing_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['tenant_id'], 'customers_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('suppliers', 'suppliers_tenant_id_foreign')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'suppliers_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('vehicles', 'vehicles_assigned_user_id_foreign')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->foreign(['assigned_user_id'], 'vehicles_assigned_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
                $table->foreign(['tenant_id'], 'vehicles_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
