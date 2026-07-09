<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('tenant_id');

            // Presenze/ore dipendenti (§12)
            $table->decimal('daily_contract_hours', 4, 2)->default(8.00)->after('is_super_admin');
            $table->decimal('weekly_contract_hours', 5, 2)->default(40.00)->after('daily_contract_hours');
            $table->unsignedInteger('annual_leave_days')->default(26)->after('weekly_contract_hours');

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn([
                'is_super_admin',
                'daily_contract_hours',
                'weekly_contract_hours',
                'annual_leave_days',
            ]);
        });
    }
};
