<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interventi: rapportini, macchine, posizionamenti, lavaggi, manutenzioni e scadenze.
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
        if (! Schema::hasTable('machine_units')) {
            Schema::create('machine_units', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('source')->default('manuale');
                $table->uuid('product_id')->nullable();
                $table->uuid('material_id')->nullable();
                $table->uuid('current_customer_id')->nullable();
                $table->uuid('billing_customer_id')->nullable();
                $table->string('serial_number');
                $table->uuid('fusione_suggerita_id')->nullable();
                $table->string('fusione_suggerita_motivo')->nullable();
                $table->uuid('fusa_in_id')->nullable();
                $table->uuid('spostamento_suggerito_customer_id')->nullable();
                $table->date('spostamento_suggerito_il')->nullable();
                $table->string('spostamento_suggerito_motivo')->nullable();
                $table->unsignedInteger('spostamento_suggerito_pagante_code')->nullable();
                $table->string('spostamento_scartato')->nullable();
                $table->string('model_name')->nullable();
                $table->string('type')->nullable();
                $table->string('maintenance_code')->nullable();
                $table->enum('status', ['in_magazzino', 'installata', 'rimossa'])->default('in_magazzino');
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unsignedInteger('gestionale_code')->nullable();
                $table->unsignedInteger('gestionale_suggested_code')->nullable();
                $table->string('gestionale_suggested_label')->nullable();
                $table->unsignedInteger('eureka_billing_customer_code')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['billing_customer_id'], 'machine_units_billing_customer_id_index');
                $table->index(['current_customer_id'], 'machine_units_current_customer_id_index');
                $table->index(['fusa_in_id'], 'machine_units_fusa_in_id_foreign');
                $table->index(['fusione_suggerita_id'], 'machine_units_fusione_suggerita_id_foreign');
                $table->index(['material_id'], 'machine_units_material_id_foreign');
                $table->index(['product_id'], 'machine_units_product_id_foreign');
                $table->index(['spostamento_suggerito_customer_id'], 'machine_units_spostamento_suggerito_customer_id_foreign');
                $table->unique(['tenant_id', 'serial_number'], 'machine_units_tenant_id_serial_number_unique');
            });
        }

        if (! Schema::hasTable('machine_unit_placements')) {
            Schema::create('machine_unit_placements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('machine_unit_id');
                $table->uuid('customer_id')->nullable();
                $table->uuid('billing_customer_id')->nullable();
                $table->unsignedInteger('eureka_billing_customer_code')->nullable();
                $table->dateTime('placed_at');
                $table->dateTime('removed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['billing_customer_id'], 'machine_unit_placements_billing_customer_id_foreign');
                $table->index(['customer_id'], 'machine_unit_placements_customer_id_foreign');
                $table->index(['machine_unit_id', 'placed_at'], 'machine_unit_placements_machine_unit_id_placed_at_index');
                $table->index(['tenant_id'], 'machine_unit_placements_tenant_id_foreign');
            });
        }

        if (! Schema::hasTable('maintenance_schedules')) {
            Schema::create('maintenance_schedules', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('type')->default('manutenzione');
                $table->string('beverage_type')->nullable();
                $table->unsignedSmallInteger('lines_count')->nullable();
                $table->enum('status', ['attivo', 'chiuso'])->default('attivo');
                $table->uuid('customer_id');
                $table->uuid('machine_unit_id')->nullable();
                $table->enum('frequency', ['mensile', 'trimestrale', 'semestrale', 'annuale'])->nullable();
                $table->unsignedSmallInteger('frequency_days')->nullable();
                $table->unsignedSmallInteger('filter_validity_days')->nullable();
                $table->uuid('last_service_report_id')->nullable();
                $table->uuid('last_lavaggio_id')->nullable();
                $table->uuid('last_filter_change_id')->nullable();
                $table->date('next_due_date')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['customer_id'], 'maintenance_schedules_customer_id_foreign');
                $table->index(['last_filter_change_id'], 'maintenance_schedules_last_filter_change_id_foreign');
                $table->index(['last_lavaggio_id'], 'maintenance_schedules_last_lavaggio_id_foreign');
                $table->index(['last_service_report_id'], 'maintenance_schedules_last_service_report_id_foreign');
                $table->index(['machine_unit_id'], 'maintenance_schedules_machine_unit_id_foreign');
                $table->index(['tenant_id', 'next_due_date'], 'maintenance_schedules_tenant_id_next_due_date_index');
                $table->index(['tenant_id', 'type', 'next_due_date'], 'maintenance_schedules_tenant_id_type_next_due_date_index');
            });
        }

        if (! Schema::hasTable('service_reports')) {
            Schema::create('service_reports', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('source')->default('manuale');
                $table->unsignedBigInteger('eureka_service_report_id')->nullable();
                $table->uuid('duplicato_suggerito_id')->nullable();
                $table->string('duplicato_suggerito_motivo')->nullable();
                $table->unsignedInteger('eureka_destinazione_code')->nullable();
                $table->string('eureka_destinazione_label')->nullable();
                $table->unsignedTinyInteger('eureka_stato_documento')->nullable();
                $table->string('eureka_stato_label')->nullable();
                $table->json('eureka_fatture')->nullable();
                $table->date('eureka_fatturato_il')->nullable();
                $table->timestamp('eureka_fatture_controllate_il')->nullable();
                $table->string('eureka_fattura_motivo', 30)->nullable();
                $table->string('eureka_fattura_indizio')->nullable();
                $table->string('number');
                $table->uuid('visita_id')->nullable();
                $table->string('gestionale_number')->nullable();
                $table->date('gestionale_document_date')->nullable();
                $table->uuid('customer_id');
                $table->uuid('billing_customer_id')->nullable();
                $table->uuid('machine_unit_id')->nullable();
                $table->uuid('quote_id')->nullable();
                $table->uuid('machine_product_id')->nullable();
                $table->uuid('machine_material_id')->nullable();
                $table->string('machine_serial_number')->nullable();
                $table->uuid('technician_id');
                $table->enum('intervention_type', ['installazione', 'disinstallazione', 'manutenzione_ordinaria', 'manutenzione_straordinaria', 'riparazione', 'garanzia', 'sanificazione']);
                $table->date('intervention_date');
                $table->dateTime('arrival_at')->nullable();
                $table->dateTime('departure_at')->nullable();
                $table->text('problem_description')->nullable();
                $table->text('work_performed')->nullable();
                $table->unsignedSmallInteger('lavaggio_vie_count')->nullable();
                $table->enum('status', ['bozza', 'completato', 'firmato', 'inviato', 'in_gestionale', 'rifiutato'])->default('bozza');
                $table->string('customer_signature_path')->nullable();
                $table->string('customer_signature_name')->nullable();
                $table->string('technician_signature_path')->nullable();
                $table->dateTime('signed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unsignedInteger('gestionale_scheda_lavoro_id')->nullable();
                $table->string('gestionale_sync_status')->nullable();
                $table->text('gestionale_sync_error')->nullable();
                $table->timestamp('gestionale_synced_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->uuid('pagante_fattura_customer_id')->nullable();
                $table->timestamp('pagante_fattura_rilevato_il')->nullable();
                $table->string('pagante_fattura_ok', 80)->nullable();
                $table->index(['billing_customer_id'], 'service_reports_billing_customer_id_foreign');
                $table->index(['customer_id'], 'service_reports_customer_id_index');
                $table->index(['duplicato_suggerito_id'], 'service_reports_duplicato_suggerito_id_foreign');
                $table->index(['eureka_service_report_id'], 'service_reports_eureka_service_report_id_index');
                $table->index(['intervention_type'], 'service_reports_intervention_type_index');
                $table->index(['machine_material_id'], 'service_reports_machine_material_id_foreign');
                $table->index(['machine_product_id'], 'service_reports_machine_product_id_foreign');
                $table->index(['machine_unit_id'], 'service_reports_machine_unit_id_foreign');
                $table->index(['pagante_fattura_customer_id'], 'service_reports_pagante_fattura_customer_id_foreign');
                $table->index(['quote_id'], 'service_reports_quote_id_foreign');
                $table->index(['technician_id'], 'service_reports_technician_id_index');
                $table->index(['tenant_id', 'eureka_fattura_motivo'], 'service_reports_tenant_id_eureka_fattura_motivo_index');
                $table->index(['tenant_id', 'eureka_fatturato_il'], 'service_reports_tenant_id_eureka_fatturato_il_index');
                $table->unique(['tenant_id', 'number'], 'service_reports_tenant_id_number_unique');
                $table->index(['visita_id'], 'service_reports_visita_id_index');
                $table->index(['status'], 'sr_status_index');
                $table->index(['tenant_id', 'intervention_date'], 'sr_tenant_data_index');
            });
        }

        if (! Schema::hasTable('service_report_materials')) {
            Schema::create('service_report_materials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('service_report_id');
                $table->uuid('material_id');
                $table->decimal('quantity', 10, 2)->default(1.00);
                $table->decimal('unit_cost_snapshot', 10, 2)->nullable();
                $table->decimal('line_total_snapshot', 10, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['material_id'], 'service_report_materials_material_id_foreign');
                $table->index(['service_report_id'], 'service_report_materials_service_report_id_index');
            });
        }

        if (! Schema::hasTable('service_report_products')) {
            Schema::create('service_report_products', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('service_report_id');
                $table->uuid('product_id');
                $table->decimal('quantity', 10, 2)->default(1.00);
                $table->decimal('unit_cost_snapshot', 10, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['product_id'], 'service_report_products_product_id_foreign');
                $table->index(['service_report_id'], 'service_report_products_service_report_id_index');
            });
        }

        if (! Schema::hasTable('service_report_emails')) {
            Schema::create('service_report_emails', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('service_report_id');
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
                $table->index(['service_report_id'], 'service_report_emails_service_report_id_index');
                $table->index(['user_id'], 'service_report_emails_user_id_foreign');
            });
        }

        if (! Schema::hasTable('service_report_maintenance_schedule')) {
            Schema::create('service_report_maintenance_schedule', function (Blueprint $table) {
                $table->uuid('service_report_id');
                $table->uuid('maintenance_schedule_id');
                $table->primary(['service_report_id', 'maintenance_schedule_id']);
                $table->index(['maintenance_schedule_id'], 'srms_maintenance_schedule_fk');
            });
        }

        if (! Schema::hasTable('lavaggi')) {
            Schema::create('lavaggi', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('customer_id');
                $table->uuid('machine_unit_id')->nullable();
                $table->uuid('service_report_id')->nullable();
                $table->date('data');
                $table->string('descrizione');
                $table->unsignedSmallInteger('lines_washed')->nullable();
                $table->boolean('filtro_sostituito')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->uuid('maintenance_schedule_id')->nullable();
                $table->index(['customer_id'], 'lavaggi_customer_id_foreign');
                $table->index(['data'], 'lavaggi_data_index');
                $table->index(['machine_unit_id'], 'lavaggi_machine_unit_id_foreign');
                $table->index(['maintenance_schedule_id'], 'lavaggi_maintenance_schedule_id_foreign');
                $table->unique(['service_report_id', 'maintenance_schedule_id'], 'lavaggi_service_report_id_maintenance_schedule_id_unique');
                $table->index(['tenant_id', 'customer_id'], 'lavaggi_tenant_id_customer_id_index');
            });
        }

        if (! Schema::hasTable('deadlines')) {
            Schema::create('deadlines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('deadlinable_type');
                $table->uuid('deadlinable_id');
                $table->enum('type', ['assicurazione', 'bollo', 'revisione', 'polizza_rct', 'manutenzione_ordinaria', 'licenza', 'contratto', 'altro']);
                $table->string('policy_number')->nullable();
                $table->date('due_date');
                $table->unsignedInteger('reminder_days_before')->default(30);
                $table->enum('status', ['attiva', 'scaduta', 'rinnovata'])->default('attiva');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['deadlinable_type', 'deadlinable_id'], 'deadlines_deadlinable_type_deadlinable_id_index');
                $table->index(['tenant_id', 'due_date'], 'deadlines_tenant_id_due_date_index');
                $table->index(['type'], 'deadlines_type_index');
            });
        }

        if (! self::haChiave('machine_units', 'machine_units_billing_customer_id_foreign')) {
            Schema::table('machine_units', function (Blueprint $table) {
                $table->foreign(['billing_customer_id'], 'machine_units_billing_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['current_customer_id'], 'machine_units_current_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['fusa_in_id'], 'machine_units_fusa_in_id_foreign')->references(['id'])->on('machine_units')->nullOnDelete();
                $table->foreign(['fusione_suggerita_id'], 'machine_units_fusione_suggerita_id_foreign')->references(['id'])->on('machine_units')->nullOnDelete();
                $table->foreign(['material_id'], 'machine_units_material_id_foreign')->references(['id'])->on('materials')->nullOnDelete();
                $table->foreign(['product_id'], 'machine_units_product_id_foreign')->references(['id'])->on('products')->nullOnDelete();
                $table->foreign(['spostamento_suggerito_customer_id'], 'machine_units_spostamento_suggerito_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['tenant_id'], 'machine_units_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('machine_unit_placements', 'machine_unit_placements_billing_customer_id_foreign')) {
            Schema::table('machine_unit_placements', function (Blueprint $table) {
                $table->foreign(['billing_customer_id'], 'machine_unit_placements_billing_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['customer_id'], 'machine_unit_placements_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['machine_unit_id'], 'machine_unit_placements_machine_unit_id_foreign')->references(['id'])->on('machine_units')->cascadeOnDelete();
                $table->foreign(['tenant_id'], 'machine_unit_placements_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('maintenance_schedules', 'maintenance_schedules_customer_id_foreign')) {
            Schema::table('maintenance_schedules', function (Blueprint $table) {
                $table->foreign(['customer_id'], 'maintenance_schedules_customer_id_foreign')->references(['id'])->on('customers')->cascadeOnDelete();
                $table->foreign(['last_filter_change_id'], 'maintenance_schedules_last_filter_change_id_foreign')->references(['id'])->on('lavaggi')->nullOnDelete();
                $table->foreign(['last_lavaggio_id'], 'maintenance_schedules_last_lavaggio_id_foreign')->references(['id'])->on('lavaggi')->nullOnDelete();
                $table->foreign(['last_service_report_id'], 'maintenance_schedules_last_service_report_id_foreign')->references(['id'])->on('service_reports')->nullOnDelete();
                $table->foreign(['machine_unit_id'], 'maintenance_schedules_machine_unit_id_foreign')->references(['id'])->on('machine_units')->nullOnDelete();
                $table->foreign(['tenant_id'], 'maintenance_schedules_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('service_reports', 'service_reports_billing_customer_id_foreign')) {
            Schema::table('service_reports', function (Blueprint $table) {
                $table->foreign(['billing_customer_id'], 'service_reports_billing_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['customer_id'], 'service_reports_customer_id_foreign')->references(['id'])->on('customers')->cascadeOnDelete();
                $table->foreign(['duplicato_suggerito_id'], 'service_reports_duplicato_suggerito_id_foreign')->references(['id'])->on('service_reports')->nullOnDelete();
                $table->foreign(['machine_material_id'], 'service_reports_machine_material_id_foreign')->references(['id'])->on('materials')->nullOnDelete();
                $table->foreign(['machine_product_id'], 'service_reports_machine_product_id_foreign')->references(['id'])->on('products')->nullOnDelete();
                $table->foreign(['machine_unit_id'], 'service_reports_machine_unit_id_foreign')->references(['id'])->on('machine_units')->nullOnDelete();
                $table->foreign(['pagante_fattura_customer_id'], 'service_reports_pagante_fattura_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['quote_id'], 'service_reports_quote_id_foreign')->references(['id'])->on('quotes')->nullOnDelete();
                $table->foreign(['technician_id'], 'service_reports_technician_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
                $table->foreign(['tenant_id'], 'service_reports_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('service_report_materials', 'service_report_materials_material_id_foreign')) {
            Schema::table('service_report_materials', function (Blueprint $table) {
                $table->foreign(['material_id'], 'service_report_materials_material_id_foreign')->references(['id'])->on('materials')->restrictOnDelete();
                $table->foreign(['service_report_id'], 'service_report_materials_service_report_id_foreign')->references(['id'])->on('service_reports')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('service_report_products', 'service_report_products_product_id_foreign')) {
            Schema::table('service_report_products', function (Blueprint $table) {
                $table->foreign(['product_id'], 'service_report_products_product_id_foreign')->references(['id'])->on('products')->restrictOnDelete();
                $table->foreign(['service_report_id'], 'service_report_products_service_report_id_foreign')->references(['id'])->on('service_reports')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('service_report_emails', 'service_report_emails_service_report_id_foreign')) {
            Schema::table('service_report_emails', function (Blueprint $table) {
                $table->foreign(['service_report_id'], 'service_report_emails_service_report_id_foreign')->references(['id'])->on('service_reports')->cascadeOnDelete();
                $table->foreign(['user_id'], 'service_report_emails_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            });
        }

        if (! self::haChiave('service_report_maintenance_schedule', 'srms_maintenance_schedule_fk')) {
            Schema::table('service_report_maintenance_schedule', function (Blueprint $table) {
                $table->foreign(['maintenance_schedule_id'], 'srms_maintenance_schedule_fk')->references(['id'])->on('maintenance_schedules')->cascadeOnDelete();
                $table->foreign(['service_report_id'], 'srms_service_report_fk')->references(['id'])->on('service_reports')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('lavaggi', 'lavaggi_customer_id_foreign')) {
            Schema::table('lavaggi', function (Blueprint $table) {
                $table->foreign(['customer_id'], 'lavaggi_customer_id_foreign')->references(['id'])->on('customers')->cascadeOnDelete();
                $table->foreign(['machine_unit_id'], 'lavaggi_machine_unit_id_foreign')->references(['id'])->on('machine_units')->nullOnDelete();
                $table->foreign(['maintenance_schedule_id'], 'lavaggi_maintenance_schedule_id_foreign')->references(['id'])->on('maintenance_schedules')->nullOnDelete();
                $table->foreign(['service_report_id'], 'lavaggi_service_report_id_foreign')->references(['id'])->on('service_reports')->cascadeOnDelete();
                $table->foreign(['tenant_id'], 'lavaggi_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('deadlines', 'deadlines_tenant_id_foreign')) {
            Schema::table('deadlines', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'deadlines_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deadlines');
        Schema::dropIfExists('lavaggi');
        Schema::dropIfExists('service_report_maintenance_schedule');
        Schema::dropIfExists('service_report_emails');
        Schema::dropIfExists('service_report_products');
        Schema::dropIfExists('service_report_materials');
        Schema::dropIfExists('service_reports');
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('machine_unit_placements');
        Schema::dropIfExists('machine_units');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
