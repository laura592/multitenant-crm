<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('due_date');
            $table->date('paid_at')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->dropColumn(['amount', 'paid_at']);
        });
    }
};
