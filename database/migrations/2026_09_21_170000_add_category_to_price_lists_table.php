<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Listini" diventa "Documenti" (21/09/2026): accanto ai listini ci stanno
 * i modelli dei contratti di assistenza, cosi' l'ufficio li aggiorna dal
 * pannello invece di passare da un rilascio. La tabella resta price_lists:
 * rinominarla avrebbe cambiato anche i permessi (price::list) di tutti i
 * ruoli in produzione. Le righe che ci sono sono tutte listini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->string('category', 40)->default('listino')->after('supplier_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
