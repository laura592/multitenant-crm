<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable: i prodotti demo/importati non hanno ancora un'attribuzione
            // brand nota. L'assegnazione ai brand reali e' materia della Fase 2
            // (scoping per brand), non di questa migrazione.
            $table->foreignUuid('brand_id')->nullable()->after('category_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
