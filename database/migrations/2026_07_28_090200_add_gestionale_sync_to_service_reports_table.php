<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->unsignedInteger('gestionale_scheda_lavoro_id')->nullable();
            $table->string('gestionale_sync_status')->nullable();
            $table->text('gestionale_sync_error')->nullable();
            $table->timestamp('gestionale_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn([
                'gestionale_scheda_lavoro_id',
                'gestionale_sync_status',
                'gestionale_sync_error',
                'gestionale_synced_at',
            ]);
        });
    }
};
