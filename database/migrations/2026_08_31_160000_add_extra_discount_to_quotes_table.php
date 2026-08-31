<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secondo sconto sul preventivo, a cascata sul primo.
 *
 * E' la forma dei listini fornitore ("30+5"): l'extra si applica su quanto
 * resta dopo lo sconto generale, non sull'imponibile pieno. Su 1.000 € con
 * 30% + 5% il totale e' 665 €, non 650 €.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('extra_discount', 5, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('extra_discount');
        });
    }
};
