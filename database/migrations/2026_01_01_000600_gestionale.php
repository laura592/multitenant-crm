<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestionale Eureka: esecuzioni, fatture, partite, saldi e contabilità.
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
        if (! Schema::hasTable('esecuzioni_eureka')) {
            Schema::create('esecuzioni_eureka', function (Blueprint $table) {
                $table->id('id');
                $table->string('comando', 80);
                $table->timestamp('avviata_il');
                $table->timestamp('finita_il')->nullable();
                $table->string('esito', 20)->default('in_corso');
                $table->json('riepilogo')->nullable();
                $table->text('errore')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['comando', 'avviata_il'], 'esecuzioni_eureka_comando_avviata_il_index');
                $table->index(['comando'], 'esecuzioni_eureka_comando_index');
            });
        }

        if (! Schema::hasTable('eureka_fatture')) {
            Schema::create('eureka_fatture', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('tipo', 16);
                $table->unsignedInteger('id_eureka');
                $table->unsignedInteger('gestionale_code')->nullable();
                $table->uuid('customer_id')->nullable();
                $table->string('ragione_sociale')->nullable();
                $table->string('partita_iva', 32)->nullable();
                $table->string('numero_doc')->nullable();
                $table->date('data_doc')->nullable();
                $table->decimal('totale_doc', 12, 2)->default(0.00);
                $table->decimal('imponibile', 12, 2)->default(0.00);
                $table->string('pagamento', 16)->nullable();
                $table->string('causale', 16)->nullable();
                $table->boolean('e_acconto')->default(false);
                $table->string('detrae_acconto_numero')->nullable();
                $table->boolean('detrazione_ambigua')->default(false);
                $table->unsignedInteger('id_b10_origine')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['customer_id'], 'eureka_fatture_customer_id_index');
                $table->index(['tenant_id', 'tipo', 'data_doc'], 'eureka_fatture_tenant_id_tipo_data_doc_index');
                $table->unique(['tenant_id', 'tipo', 'id_eureka'], 'eureka_fatture_tenant_id_tipo_id_eureka_unique');
            });
        }

        if (! Schema::hasTable('eureka_partite_aperte')) {
            Schema::create('eureka_partite_aperte', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('tipo', 16);
                $table->unsignedInteger('gestionale_code');
                $table->uuid('customer_id')->nullable();
                $table->string('ragione_sociale')->nullable();
                $table->unsignedSmallInteger('anno');
                $table->string('numero_fattura')->nullable();
                $table->date('data_fattura')->nullable();
                $table->date('data_scadenza')->nullable();
                $table->string('tipo_pagamento')->nullable();
                $table->decimal('saldo', 12, 2);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['customer_id'], 'eureka_partite_aperte_customer_id_index');
                $table->index(['tenant_id', 'data_scadenza'], 'eureka_partite_aperte_tenant_id_data_scadenza_index');
                $table->index(['tenant_id', 'tipo'], 'eureka_partite_aperte_tenant_id_tipo_index');
            });
        }

        if (! Schema::hasTable('eureka_saldi_anagrafiche')) {
            Schema::create('eureka_saldi_anagrafiche', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('tipo', 16);
                $table->unsignedInteger('gestionale_code');
                $table->string('ragione_sociale')->nullable();
                $table->decimal('saldo', 12, 2);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['tenant_id', 'tipo', 'gestionale_code'], 'eureka_saldi_anagrafiche_tenant_id_tipo_gestionale_code_unique');
            });
        }

        if (! Schema::hasTable('eureka_fatturato_mesi')) {
            Schema::create('eureka_fatturato_mesi', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('tipo', 16);
                $table->unsignedSmallInteger('anno');
                $table->unsignedTinyInteger('mese');
                $table->decimal('dare', 14, 2)->default(0.00);
                $table->decimal('avere', 14, 2)->default(0.00);
                $table->decimal('netto', 14, 2)->default(0.00);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['tenant_id', 'tipo', 'anno', 'mese'], 'eureka_fatturato_mesi_tenant_id_tipo_anno_mese_unique');
            });
        }

        if (! Schema::hasTable('eureka_cashflow_mesi')) {
            Schema::create('eureka_cashflow_mesi', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->unsignedSmallInteger('anno');
                $table->unsignedTinyInteger('mese');
                $table->decimal('entrate', 14, 2)->default(0.00);
                $table->decimal('uscite', 14, 2)->default(0.00);
                $table->decimal('entrate_ftc', 14, 2)->default(0.00);
                $table->decimal('entrate_oc', 14, 2)->default(0.00);
                $table->decimal('entrate_bc', 14, 2)->default(0.00);
                $table->decimal('uscite_ftf', 14, 2)->default(0.00);
                $table->decimal('uscite_of', 14, 2)->default(0.00);
                $table->decimal('uscite_bf', 14, 2)->default(0.00);
                $table->decimal('saldo_mese', 14, 2)->default(0.00);
                $table->decimal('saldo_progressivo', 14, 2)->default(0.00);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['tenant_id', 'anno', 'mese'], 'eureka_cashflow_mesi_tenant_id_anno_mese_unique');
            });
        }

        if (! Schema::hasTable('eureka_cashflow_voci')) {
            Schema::create('eureka_cashflow_voci', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->unsignedSmallInteger('anno');
                $table->unsignedTinyInteger('mese');
                $table->date('data_documento')->nullable();
                $table->date('data_scadenza')->nullable();
                $table->string('numero')->nullable();
                $table->string('descrizione')->nullable();
                $table->string('tipo', 8)->nullable();
                $table->decimal('importo_totale', 14, 2)->default(0.00);
                $table->decimal('importo', 14, 2)->default(0.00);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['tenant_id', 'anno', 'mese'], 'eureka_cashflow_voci_tenant_id_anno_mese_index');
            });
        }

        if (! self::haChiave('eureka_fatture', 'eureka_fatture_customer_id_foreign')) {
            Schema::table('eureka_fatture', function (Blueprint $table) {
                $table->foreign(['customer_id'], 'eureka_fatture_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['tenant_id'], 'eureka_fatture_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('eureka_partite_aperte', 'eureka_partite_aperte_customer_id_foreign')) {
            Schema::table('eureka_partite_aperte', function (Blueprint $table) {
                $table->foreign(['customer_id'], 'eureka_partite_aperte_customer_id_foreign')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['tenant_id'], 'eureka_partite_aperte_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('eureka_saldi_anagrafiche', 'eureka_saldi_anagrafiche_tenant_id_foreign')) {
            Schema::table('eureka_saldi_anagrafiche', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'eureka_saldi_anagrafiche_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('eureka_fatturato_mesi', 'eureka_fatturato_mesi_tenant_id_foreign')) {
            Schema::table('eureka_fatturato_mesi', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'eureka_fatturato_mesi_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('eureka_cashflow_mesi', 'eureka_cashflow_mesi_tenant_id_foreign')) {
            Schema::table('eureka_cashflow_mesi', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'eureka_cashflow_mesi_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('eureka_cashflow_voci', 'eureka_cashflow_voci_tenant_id_foreign')) {
            Schema::table('eureka_cashflow_voci', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'eureka_cashflow_voci_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eureka_cashflow_voci');
        Schema::dropIfExists('eureka_cashflow_mesi');
        Schema::dropIfExists('eureka_fatturato_mesi');
        Schema::dropIfExists('eureka_saldi_anagrafiche');
        Schema::dropIfExists('eureka_partite_aperte');
        Schema::dropIfExists('eureka_fatture');
        Schema::dropIfExists('esecuzioni_eureka');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
