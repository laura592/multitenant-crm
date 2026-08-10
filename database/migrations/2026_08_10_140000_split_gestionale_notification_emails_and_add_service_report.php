<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('notify_customer_gestionale_review_emails')->nullable()->after('notify_customer_gestionale_emails');
            $table->json('notify_gestionale_sync_digest_emails')->nullable()->after('notify_customer_gestionale_review_emails');
            $table->json('notify_gestionale_sync_failed_emails')->nullable()->after('notify_gestionale_sync_digest_emails');
            $table->json('notify_service_report_emails')->nullable()->after('notify_gestionale_sync_failed_emails');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'notify_customer_gestionale_review_emails',
                'notify_gestionale_sync_digest_emails',
                'notify_gestionale_sync_failed_emails',
                'notify_service_report_emails',
            ]);
        });
    }
};
