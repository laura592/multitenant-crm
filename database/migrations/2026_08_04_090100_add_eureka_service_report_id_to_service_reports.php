<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('eureka_service_report_id')->nullable()->after('tenant_id');
            $table->index('eureka_service_report_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropIndex(['eureka_service_report_id']);
            $table->dropColumn('eureka_service_report_id');
        });
    }
};
