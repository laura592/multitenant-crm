<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_orders', function (Blueprint $table) {
            $table->string('number')->nullable()->after('tenant_id');
            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('material_orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'number']);
            $table->dropColumn('number');
        });
    }
};
