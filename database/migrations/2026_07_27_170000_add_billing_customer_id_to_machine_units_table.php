<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->foreignUuid('billing_customer_id')->nullable()->after('current_customer_id')
                ->constrained('customers')->nullOnDelete();
            $table->index('billing_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_customer_id');
        });
    }
};
