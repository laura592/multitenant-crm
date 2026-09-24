<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offerte caffè: listino e documenti.
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
        if (! Schema::hasTable('prodotti_caffe')) {
            Schema::create('prodotti_caffe', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('gruppo');
                $table->string('nome');
                $table->string('formato')->nullable();
                $table->decimal('prezzo', 10, 2);
                $table->unsignedInteger('ordinamento')->default(0);
                $table->boolean('attivo')->default(true);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('offerte_caffe')) {
            Schema::create('offerte_caffe', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('customer_id');
                $table->uuid('user_id')->nullable();
                $table->string('number');
                $table->date('date');
                $table->date('valida_fino')->nullable();
                $table->json('righe');
                $table->text('note')->nullable();
                $table->string('status')->default('bozza');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['customer_id'], 'offerte_caffe_customer_id_foreign');
                $table->index(['tenant_id', 'number'], 'offerte_caffe_tenant_id_number_index');
                $table->index(['user_id'], 'offerte_caffe_user_id_foreign');
            });
        }

        if (! Schema::hasTable('offerta_caffe_emails')) {
            Schema::create('offerta_caffe_emails', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('offerta_caffe_id');
                $table->uuid('user_id')->nullable();
                $table->string('inviata_con')->nullable();
                $table->string('recipient_email');
                $table->string('cc_email')->nullable();
                $table->string('subject')->nullable();
                $table->longText('message')->nullable();
                $table->string('status')->default('sent');
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['offerta_caffe_id'], 'offerta_caffe_emails_offerta_caffe_id_foreign');
                $table->index(['user_id'], 'offerta_caffe_emails_user_id_foreign');
            });
        }

        if (! self::haChiave('offerte_caffe', 'offerte_caffe_customer_id_foreign')) {
            Schema::table('offerte_caffe', function (Blueprint $table) {
                $table->foreign(['customer_id'], 'offerte_caffe_customer_id_foreign')->references(['id'])->on('customers')->cascadeOnDelete();
                $table->foreign(['tenant_id'], 'offerte_caffe_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
                $table->foreign(['user_id'], 'offerte_caffe_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            });
        }

        if (! self::haChiave('offerta_caffe_emails', 'offerta_caffe_emails_offerta_caffe_id_foreign')) {
            Schema::table('offerta_caffe_emails', function (Blueprint $table) {
                $table->foreign(['offerta_caffe_id'], 'offerta_caffe_emails_offerta_caffe_id_foreign')->references(['id'])->on('offerte_caffe')->cascadeOnDelete();
                $table->foreign(['user_id'], 'offerta_caffe_emails_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offerta_caffe_emails');
        Schema::dropIfExists('offerte_caffe');
        Schema::dropIfExists('prodotti_caffe');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
