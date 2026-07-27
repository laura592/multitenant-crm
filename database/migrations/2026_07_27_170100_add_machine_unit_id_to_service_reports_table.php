<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->foreignUuid('machine_unit_id')->nullable()->after('comodato_macchina_id')
                ->constrained('machine_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_unit_id');
        });
    }
};
