<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogo condiviso (tenant_id nullable, stesso pattern di categories/products):
        // listino fornitore (es. John Guest) usato dai tecnici per ordinare raccordi,
        // valvole e tubi per impianti idrici/birra alla spina.
        Schema::create('materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code')->unique();
            $table->string('category');
            $table->string('type');
            $table->string('variant')->nullable();
            $table->string('tube_diameter')->nullable();
            $table->string('tube_diameter_2')->nullable();
            $table->string('thread_size')->nullable();
            $table->string('thread_type')->nullable();
            $table->string('barb_diameter')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
