<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('gestionale_suggested_code')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('gestionale_suggested_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('gestionale_suggested_code');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('gestionale_suggested_code');
        });
    }
};
