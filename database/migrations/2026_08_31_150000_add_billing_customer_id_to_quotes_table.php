<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagante scegliibile sul singolo preventivo.
 *
 * Finora il PDF ricavava la riga "Fatturato a" dal pagante del cliente, e
 * non c'era modo di dire "questo preventivo lo intesto e lo fatturo alla
 * stessa persona a cui l'ho fatto". Stesso problema gia' risolto sui
 * rapportini (2026_08_31_120000), stessa soluzione.
 *
 * NULL = pagante abituale del cliente, cioe' il comportamento di prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignUuid('billing_customer_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_customer_id');
        });
    }
};
