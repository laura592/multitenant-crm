<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('tax_code')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('sdi')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('quote_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->enum('status', ['bozza', 'inviato', 'scelto', 'scaduto'])->default('bozza');
            $table->timestamp('sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('quote_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();

            $table->string('number');
            $table->date('date');
            $table->string('status')->default('bozza');
            $table->decimal('discount', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('payment_method')->nullable(); // slug, vedi PaymentMethod
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);

            // Provvigioni partner (§4.3), valorizzate solo se il tenant non è master
            $table->enum('commission_scenario', ['A', 'B', 'C'])->nullable();
            $table->decimal('commission_rate_snapshot', 10, 2)->nullable();
            $table->decimal('commission_amount', 10, 2)->nullable();
            $table->enum('commission_direction', ['partner_to_master', 'master_to_partner'])->nullable();
            $table->enum('commission_status', ['da_fatturare', 'fatturata', 'pagata'])->nullable();
            $table->string('commission_invoice_number')->nullable();
            $table->date('commission_invoiced_at')->nullable();
            $table->date('commission_due_at')->nullable();
            $table->date('commission_paid_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index('customer_id');
            $table->index('commission_status');
        });

        Schema::create('quote_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('parent_quote_product_id')->nullable()->constrained('quote_products')->cascadeOnDelete();

            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('discount')->default(0);
            $table->integer('tax')->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();

            $table->index('quote_id');
            $table->index('parent_quote_product_id');
        });

        Schema::create('quote_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject');
            $table->text('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('quote_id');
        });

        Schema::create('quote_group_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject');
            $table->text('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('quote_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_group_emails');
        Schema::dropIfExists('quote_emails');
        Schema::dropIfExists('quote_products');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('quote_groups');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('payment_methods');
    }
};
