<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('notify_information_request_emails')->nullable()->after('notify_staff_emails');
            $table->json('notify_leave_request_emails')->nullable()->after('notify_information_request_emails');
            $table->json('notify_quote_emails')->nullable()->after('notify_leave_request_emails');
            $table->json('notify_quote_group_emails')->nullable()->after('notify_quote_emails');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'notify_information_request_emails',
                'notify_leave_request_emails',
                'notify_quote_emails',
                'notify_quote_group_emails',
            ]);
        });
    }
};
