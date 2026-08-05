<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('eureka_article_id')->nullable()->after('legacy_id');
            $table->index('eureka_article_id');
        });

        Schema::table('machine_units', function (Blueprint $table) {
            $table->unsignedBigInteger('eureka_article_id')->nullable()->after('billing_customer_id');
            $table->unsignedBigInteger('eureka_matricola_id')->nullable()->after('eureka_article_id');
            $table->index('eureka_article_id');
            $table->index('eureka_matricola_id');
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropIndex(['eureka_matricola_id']);
            $table->dropIndex(['eureka_article_id']);
            $table->dropColumn(['eureka_article_id', 'eureka_matricola_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['eureka_article_id']);
            $table->dropColumn('eureka_article_id');
        });
    }
};
