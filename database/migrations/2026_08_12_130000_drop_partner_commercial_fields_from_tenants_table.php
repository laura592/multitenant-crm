<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Non esistono ancora tenant partner (solo Alex, il master): tutta la
// logica commerciale/contrattuale pensata per loro (sconto macchine,
// scenari provvigione, esclusiva territoriale, canone piattaforma, ...)
// viene rimossa invece di restare dormiente - vedi anche la migration
// gemella sulle colonne commission_* di quotes. Se in futuro arriva un
// partner, va ridisegnata da capo con i requisiti reali del momento.
// Thread 2026-08-12.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'machine_discount_percent',
                'default_commission_scenario',
                'scenario_a_commission_percent',
                'scenario_b_installation_fee',
                'scenario_c_preinstallation_fee',
                'exclusive_supply_required',
                'territory_exclusive',
                'territory_notes',
                'contract_start_date',
                'contract_duration_months',
                'notice_period_days',
                'saas_billing_enabled',
                'saas_plan_fee',
                'saas_billing_cycle',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
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
            $table->boolean('saas_billing_enabled')->default(false);
            $table->decimal('saas_plan_fee', 10, 2)->nullable();
            $table->enum('saas_billing_cycle', ['monthly', 'annual'])->nullable();
        });
    }
};
