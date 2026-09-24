<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preventivi, gruppi di preventivi e richieste informazioni.
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
        if (! Schema::hasTable('quotes')) {
            Schema::create('quotes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('quote_group_id')->nullable();
                $table->uuid('information_request_id')->nullable();
                $table->uuid('customer_id');
                $table->uuid('billing_customer_id')->nullable();
                $table->string('number');
                $table->date('date');
                $table->string('status')->default('bozza');
                $table->decimal('discount', 5, 2)->default(0.00);
                $table->decimal('extra_discount', 5, 2)->default(0.00);
                $table->text('notes')->nullable();
                $table->string('payment_method')->nullable();
                $table->decimal('rental_monthly_fee', 10, 2)->nullable();
                $table->unsignedSmallInteger('rental_months')->nullable();
                $table->decimal('subtotal', 10, 2)->default(0.00);
                $table->decimal('tax_total', 10, 2)->default(0.00);
                $table->decimal('total', 10, 2)->default(0.00);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->string('public_token', 64)->nullable();
                $table->timestamp('client_first_viewed_at')->nullable();
                $table->timestamp('client_last_viewed_at')->nullable();
                $table->unsignedInteger('client_view_count')->default(0);
                $table->index(['billing_customer_id'], 'quotes_billing_customer_id_foreign');
                $table->index(['customer_id'], 'quotes_customer_id_index');
                $table->index(['information_request_id'], 'quotes_information_request_id_foreign');
                $table->unique(['public_token'], 'quotes_public_token_unique');
                $table->index(['quote_group_id'], 'quotes_quote_group_id_foreign');
                $table->unique(['tenant_id', 'number'], 'quotes_tenant_id_number_unique');
            });
        }

        if (! Schema::hasTable('quote_products')) {
            Schema::create('quote_products', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('quote_id');
                $table->uuid('product_id');
                $table->uuid('parent_quote_product_id')->nullable();
                $table->decimal('quantity', 10, 2)->default(1.00);
                $table->decimal('price', 10, 2)->default(0.00);
                $table->integer('discount')->default(0);
                $table->integer('tax')->default(0);
                $table->decimal('total', 10, 2)->default(0.00);
                $table->string('contratto_assistenza', 10)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['parent_quote_product_id'], 'quote_products_parent_quote_product_id_index');
                $table->index(['product_id'], 'quote_products_product_id_foreign');
                $table->index(['quote_id'], 'quote_products_quote_id_index');
            });
        }

        if (! Schema::hasTable('quote_groups')) {
            Schema::create('quote_groups', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('customer_id');
                $table->string('number');
                $table->enum('status', ['bozza', 'inviato', 'scelto', 'scaduto'])->default('bozza');
                $table->timestamp('sent_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->string('public_token', 64)->nullable();
                $table->timestamp('client_first_viewed_at')->nullable();
                $table->timestamp('client_last_viewed_at')->nullable();
                $table->unsignedInteger('client_view_count')->default(0);
                $table->index(['customer_id'], 'quote_groups_customer_id_foreign');
                $table->unique(['public_token'], 'quote_groups_public_token_unique');
                $table->unique(['tenant_id', 'number'], 'quote_groups_tenant_id_number_unique');
            });
        }

        if (! Schema::hasTable('quote_emails')) {
            Schema::create('quote_emails', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('quote_id');
                $table->uuid('user_id')->nullable();
                $table->string('recipient_email');
                $table->string('cc_email')->nullable();
                $table->string('subject');
                $table->text('message')->nullable();
                $table->string('status')->default('sent');
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['quote_id'], 'quote_emails_quote_id_index');
                $table->index(['user_id'], 'quote_emails_user_id_foreign');
            });
        }

        if (! Schema::hasTable('quote_group_emails')) {
            Schema::create('quote_group_emails', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('quote_group_id');
                $table->uuid('user_id')->nullable();
                $table->string('recipient_email');
                $table->string('cc_email')->nullable();
                $table->string('subject');
                $table->text('message')->nullable();
                $table->string('status')->default('sent');
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['quote_group_id'], 'quote_group_emails_quote_group_id_index');
                $table->index(['user_id'], 'quote_group_emails_user_id_foreign');
            });
        }

        if (! Schema::hasTable('quote_responses')) {
            Schema::create('quote_responses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->uuid('quote_id')->nullable();
                $table->uuid('quote_group_id')->nullable();
                $table->string('type', 20);
                $table->string('signer_name')->nullable();
                $table->string('signer_role')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('preferred_time', 30)->nullable();
                $table->string('reason', 40)->nullable();
                $table->text('message')->nullable();
                $table->string('signature_path')->nullable();
                $table->string('accepted_pdf_path')->nullable();
                $table->string('accepted_pdf_sha256', 64)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->uuid('handled_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['handled_by'], 'quote_responses_handled_by_foreign');
                $table->index(['quote_group_id'], 'quote_responses_quote_group_id_foreign');
                $table->index(['quote_id'], 'quote_responses_quote_id_foreign');
                $table->index(['tenant_id'], 'quote_responses_tenant_id_foreign');
                $table->index(['type', 'handled_at'], 'quote_responses_type_handled_at_index');
            });
        }

        if (! Schema::hasTable('information_requests')) {
            Schema::create('information_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('customer_id');
                $table->string('number');
                $table->text('request_details')->nullable();
                $table->string('status')->default('nuova');
                $table->string('source')->default('crm');
                $table->string('origin_url')->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('external_id')->nullable();
                $table->dateTime('appointment_at')->nullable();
                $table->text('appointment_notes')->nullable();
                $table->uuid('handled_by_user_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['customer_id'], 'information_requests_customer_id_index');
                $table->index(['handled_by_user_id'], 'information_requests_handled_by_user_id_foreign');
                $table->unique(['tenant_id', 'external_id'], 'information_requests_tenant_id_external_id_unique');
                $table->unique(['tenant_id', 'number'], 'information_requests_tenant_id_number_unique');
            });
        }

        if (! Schema::hasTable('information_request_notes')) {
            Schema::create('information_request_notes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('information_request_id');
                $table->date('logged_at');
                $table->text('body');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['information_request_id'], 'information_request_notes_information_request_id_index');
                $table->index(['tenant_id'], 'information_request_notes_tenant_id_foreign');
            });
        }

        if (! Schema::hasTable('information_request_product')) {
            Schema::create('information_request_product', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('information_request_id');
                $table->uuid('product_id');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['information_request_id', 'product_id'], 'info_request_product_unique');
                $table->index(['product_id'], 'information_request_product_product_id_foreign');
            });
        }

        if (! self::haChiave('quotes', 'quotes_billing_customer_id_foreign')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->foreign(['billing_customer_id'], 'quotes_billing_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['customer_id'], 'quotes_customer_id_foreign')->references(['id'])->on('customers')->cascadeOnDelete();
                $table->foreign(['information_request_id'], 'quotes_information_request_id_foreign')->references(['id'])->on('information_requests')->nullOnDelete();
                $table->foreign(['quote_group_id'], 'quotes_quote_group_id_foreign')->references(['id'])->on('quote_groups')->nullOnDelete();
                $table->foreign(['tenant_id'], 'quotes_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('quote_products', 'quote_products_parent_quote_product_id_foreign')) {
            Schema::table('quote_products', function (Blueprint $table) {
                $table->foreign(['parent_quote_product_id'], 'quote_products_parent_quote_product_id_foreign')->references(['id'])->on('quote_products')->cascadeOnDelete();
                $table->foreign(['product_id'], 'quote_products_product_id_foreign')->references(['id'])->on('products')->restrictOnDelete();
                $table->foreign(['quote_id'], 'quote_products_quote_id_foreign')->references(['id'])->on('quotes')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('quote_groups', 'quote_groups_customer_id_foreign')) {
            Schema::table('quote_groups', function (Blueprint $table) {
                $table->foreign(['customer_id'], 'quote_groups_customer_id_foreign')->references(['id'])->on('customers')->cascadeOnDelete();
                $table->foreign(['tenant_id'], 'quote_groups_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('quote_emails', 'quote_emails_quote_id_foreign')) {
            Schema::table('quote_emails', function (Blueprint $table) {
                $table->foreign(['quote_id'], 'quote_emails_quote_id_foreign')->references(['id'])->on('quotes')->cascadeOnDelete();
                $table->foreign(['user_id'], 'quote_emails_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            });
        }

        if (! self::haChiave('quote_group_emails', 'quote_group_emails_quote_group_id_foreign')) {
            Schema::table('quote_group_emails', function (Blueprint $table) {
                $table->foreign(['quote_group_id'], 'quote_group_emails_quote_group_id_foreign')->references(['id'])->on('quote_groups')->cascadeOnDelete();
                $table->foreign(['user_id'], 'quote_group_emails_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            });
        }

        if (! self::haChiave('quote_responses', 'quote_responses_handled_by_foreign')) {
            Schema::table('quote_responses', function (Blueprint $table) {
                $table->foreign(['handled_by'], 'quote_responses_handled_by_foreign')->references(['id'])->on('users')->nullOnDelete();
                $table->foreign(['quote_group_id'], 'quote_responses_quote_group_id_foreign')->references(['id'])->on('quote_groups')->nullOnDelete();
                $table->foreign(['quote_id'], 'quote_responses_quote_id_foreign')->references(['id'])->on('quotes')->nullOnDelete();
                $table->foreign(['tenant_id'], 'quote_responses_tenant_id_foreign')->references(['id'])->on('tenants')->nullOnDelete();
            });
        }

        if (! self::haChiave('information_requests', 'information_requests_customer_id_foreign')) {
            Schema::table('information_requests', function (Blueprint $table) {
                $table->foreign(['customer_id'], 'information_requests_customer_id_foreign')->references(['id'])->on('customers')->cascadeOnDelete();
                $table->foreign(['handled_by_user_id'], 'information_requests_handled_by_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
                $table->foreign(['tenant_id'], 'information_requests_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('information_request_notes', 'information_request_notes_information_request_id_foreign')) {
            Schema::table('information_request_notes', function (Blueprint $table) {
                $table->foreign(['information_request_id'], 'information_request_notes_information_request_id_foreign')->references(['id'])->on('information_requests')->cascadeOnDelete();
                $table->foreign(['tenant_id'], 'information_request_notes_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('information_request_product', 'information_request_product_information_request_id_foreign')) {
            Schema::table('information_request_product', function (Blueprint $table) {
                $table->foreign(['information_request_id'], 'information_request_product_information_request_id_foreign')->references(['id'])->on('information_requests')->cascadeOnDelete();
                $table->foreign(['product_id'], 'information_request_product_product_id_foreign')->references(['id'])->on('products')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('information_request_product');
        Schema::dropIfExists('information_request_notes');
        Schema::dropIfExists('information_requests');
        Schema::dropIfExists('quote_responses');
        Schema::dropIfExists('quote_group_emails');
        Schema::dropIfExists('quote_emails');
        Schema::dropIfExists('quote_groups');
        Schema::dropIfExists('quote_products');
        Schema::dropIfExists('quotes');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
