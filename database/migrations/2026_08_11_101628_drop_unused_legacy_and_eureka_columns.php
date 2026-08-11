<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * legacy_id: usato solo da ImportLegacyData (App\Console\Commands),
     * gia' rimosso (commit f01ea6b). handled_by: sostituito da
     * handled_by_user_id. eureka_matricola_id/eureka_article_id su
     * machine_units: mai popolate, nessun codice le legge o scrive
     * (a differenza di products.eureka_article_id, che resta).
     */
    private const LEGACY_ID_TABLES = [
        'payment_methods',
        'users',
        'categories',
        'products',
        'product_prices',
        'customers',
        'quotes',
        'quote_products',
        'quote_emails',
        'information_requests',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_ID_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex("{$table}_legacy_id_index");
                $blueprint->dropColumn('legacy_id');
            });
        }

        Schema::table('information_requests', function (Blueprint $table) {
            $table->dropColumn('handled_by');
        });

        Schema::table('machine_units', function (Blueprint $table) {
            $table->dropIndex(['eureka_matricola_id']);
            $table->dropIndex(['eureka_article_id']);
            $table->dropColumn(['eureka_article_id', 'eureka_matricola_id']);
        });
    }

    public function down(): void
    {
        Schema::table('machine_units', function (Blueprint $table) {
            $table->unsignedBigInteger('eureka_article_id')->nullable()->after('billing_customer_id');
            $table->unsignedBigInteger('eureka_matricola_id')->nullable()->after('eureka_article_id');
            $table->index('eureka_article_id');
            $table->index('eureka_matricola_id');
        });

        Schema::table('information_requests', function (Blueprint $table) {
            $table->string('handled_by')->nullable();
        });

        foreach (self::LEGACY_ID_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unsignedBigInteger('legacy_id')->nullable()->after('id');
                $blueprint->index('legacy_id');
            });
        }
    }
};
