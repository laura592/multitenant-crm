<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invio email fornitore rimosso dagli ordini materiali.
        Schema::dropIfExists('material_order_emails');
    }

    public function down(): void
    {
        Schema::create('material_order_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('material_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email');
            $table->string('cc_email')->nullable();
            $table->string('subject');
            $table->text('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('material_order_id');
        });
    }
};
