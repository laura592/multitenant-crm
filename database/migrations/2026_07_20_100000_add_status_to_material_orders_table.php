<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_orders', function (Blueprint $table) {
            // Aggiornato dalle action (invio email -> inviato, "Segna come
            // ricevuto" -> ricevuto), non modificabile a mano dal form.
            $table->enum('status', ['bozza', 'inviato', 'ricevuto'])->default('bozza')->after('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('material_orders', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
