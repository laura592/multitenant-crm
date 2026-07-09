<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Anagrafica
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('tax_code')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('slug')->unique();

            $table->boolean('is_master')->default(false);
            $table->boolean('is_active')->default(true);

            // Branding
            $table->string('logo_path')->nullable();
            $table->string('primary_color')->nullable();

            // Condizioni contrattuali (contratto tipo distribuzione, art. 3/4/11/12/13)
            $table->decimal('machine_discount_percent', 5, 2)->default(30.00);
            $table->enum('default_commission_scenario', ['A', 'B', 'C'])->nullable();
            $table->decimal('scenario_a_commission_percent', 5, 2)->default(10.00);
            $table->decimal('scenario_b_installation_fee', 10, 2)->default(1500.00);
            $table->decimal('scenario_c_preinstallation_fee', 10, 2)->default(500.00);
            $table->boolean('exclusive_supply_required')->default(true);
            $table->boolean('territory_exclusive')->default(false);
            $table->text('territory_notes')->nullable();
            $table->date('contract_start_date')->nullable();
            $table->unsignedInteger('contract_duration_months')->default(36);
            $table->unsignedInteger('notice_period_days')->default(90);

            // Canone SaaS (opzionale, per singolo tenant)
            $table->boolean('saas_billing_enabled')->default(false);
            $table->decimal('saas_plan_fee', 10, 2)->nullable();
            $table->enum('saas_billing_cycle', ['monthly', 'annual'])->nullable();

            $table->timestamps();

            $table->index('is_active');
            $table->index('is_master');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
