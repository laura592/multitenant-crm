<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stato ordine (bozza/inviato/ricevuto) rimosso dall'interfaccia.
        Schema::table('material_orders', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('material_orders', function (Blueprint $table) {
            $table->enum('status', ['bozza', 'inviato', 'ricevuto'])->default('bozza')->after('supplier_id');
        });
    }
};
