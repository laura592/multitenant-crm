<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->unsignedInteger('gestionale_code')->nullable()->comment('id m14 (matricola) su Eureka');
            $table->unsignedInteger('gestionale_suggested_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropColumn(['gestionale_code', 'gestionale_suggested_code']);
        });
    }
};
