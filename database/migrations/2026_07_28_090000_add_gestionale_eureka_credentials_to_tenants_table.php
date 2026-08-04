<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('gestionale_eureka_base_url')->nullable();
            $table->string('gestionale_eureka_username')->nullable();
            $table->text('gestionale_eureka_password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['gestionale_eureka_base_url', 'gestionale_eureka_username', 'gestionale_eureka_password']);
        });
    }
};
