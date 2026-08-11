<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->string('source')->default('manuale')->after('tenant_id');
        });

        // Backfill: fino ad oggi l'unico creatore di rapportini "SL-..." e'
        // ImportEurekaServiceReports (numerazione "RT-..." e' invece sempre
        // ServiceReport::nextNumberForTenant, lato CRM) — unico modo per
        // ricostruire l'origine reale dei rapportini gia' esistenti.
        DB::table('service_reports')
            ->where('number', 'like', 'SL-%')
            ->update(['source' => 'eureka']);
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
