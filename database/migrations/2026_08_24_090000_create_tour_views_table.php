<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('page_slug');
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->unique(['user_id', 'page_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_views');
    }
};
