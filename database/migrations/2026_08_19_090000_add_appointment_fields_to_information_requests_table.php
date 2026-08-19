<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('information_requests', function (Blueprint $table) {
            $table->dateTime('appointment_at')->nullable()->after('status');
            $table->text('appointment_notes')->nullable()->after('appointment_at');
        });
    }

    public function down(): void
    {
        Schema::table('information_requests', function (Blueprint $table) {
            $table->dropColumn(['appointment_at', 'appointment_notes']);
        });
    }
};
