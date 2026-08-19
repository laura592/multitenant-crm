<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('information_request_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('information_request_id')->constrained()->cascadeOnDelete();

            $table->date('logged_at');
            $table->text('body');

            $table->timestamps();

            $table->index('information_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('information_request_notes');
    }
};
