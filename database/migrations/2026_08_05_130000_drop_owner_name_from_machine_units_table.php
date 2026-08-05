<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proprieta' legale rimossa: non tracciata, si usa "Fatturare a" (billing_customer_id).
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn('owner_name');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('model_name');
        });
    }
};
