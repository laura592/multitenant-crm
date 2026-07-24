<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // Canone mensile del noleggio operativo (es. tramite Grenke),
            // mostrato in PDF sotto "Condizioni di pagamento" solo quando
            // payment_method e' 'noleggio-operativo'. Vedi QuoteResource.
            $table->decimal('rental_monthly_fee', 10, 2)->nullable()->after('payment_method');
            $table->unsignedSmallInteger('rental_months')->nullable()->after('rental_monthly_fee');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['rental_monthly_fee', 'rental_months']);
        });
    }
};
