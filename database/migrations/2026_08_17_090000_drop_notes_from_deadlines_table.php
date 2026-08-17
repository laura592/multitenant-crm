<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Campo note libero mai usato in pratica sullo scadenzario. Thread 2026-08-17.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }

    public function down(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });
    }
};
