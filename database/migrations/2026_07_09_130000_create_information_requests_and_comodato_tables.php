<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('information_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();

            $table->string('number');
            $table->text('request_details')->nullable();
            $table->string('status')->default('nuova');
            $table->string('handled_by')->nullable(); // legacy: testo libero
            $table->foreignUuid('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index('customer_id');
        });

        Schema::create('information_request_product', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('information_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['information_request_id', 'product_id'], 'info_request_product_unique');
        });

        Schema::create('comodato_macchine', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('nome_macchina');
            $table->decimal('costo_macchina', 10, 2);
            $table->decimal('costo_attrezzatura', 10, 2)->default(0);
            $table->unsignedInteger('anni_ammortamento');
            $table->decimal('prezzo_annuale_consumabili', 10, 2)->default(0);
            $table->decimal('costi_manutenzione_annui', 10, 2)->default(0);
            $table->decimal('costo_caffe_per_battitura', 10, 4)->default(0);
            $table->unsignedInteger('erogazioni_annuali_minime')->nullable();
            $table->unsignedInteger('erogazioni_previste_annue')->nullable();
            $table->decimal('canone_fisso_annuale', 10, 2)->default(0);
            $table->decimal('margine_percentuale', 5, 2)->default(0);
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comodato_macchine');
        Schema::dropIfExists('information_request_product');
        Schema::dropIfExists('information_requests');
    }
};
