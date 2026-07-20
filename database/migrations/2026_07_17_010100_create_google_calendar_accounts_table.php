<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('google_account_email');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('token_expires_at')->nullable();
            $table->string('calendar_id');
            $table->string('sync_token')->nullable();
            $table->dateTime('connected_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_accounts');
    }
};