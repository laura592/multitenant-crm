<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vedi la migration gemella sulle colonne partner di tenants: la
// "Provvigione partner" (Quote::commissionAttributes()) non ha piu' motivo
// di esistere finche' non esiste un tenant partner reale. Thread 2026-08-12.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex('quotes_commission_status_index');
            $table->dropColumn([
                'commission_scenario',
                'commission_rate_snapshot',
                'commission_amount',
                'commission_direction',
                'commission_status',
                'commission_invoice_number',
                'commission_invoiced_at',
                'commission_due_at',
                'commission_paid_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->enum('commission_scenario', ['A', 'B', 'C'])->nullable();
            $table->decimal('commission_rate_snapshot', 10, 2)->nullable();
            $table->decimal('commission_amount', 10, 2)->nullable();
            $table->enum('commission_direction', ['partner_to_master', 'master_to_partner'])->nullable();
            $table->enum('commission_status', ['da_fatturare', 'fatturata', 'pagata'])->nullable();
            $table->string('commission_invoice_number')->nullable();
            $table->date('commission_invoiced_at')->nullable();
            $table->date('commission_due_at')->nullable();
            $table->date('commission_paid_at')->nullable();
            $table->index('commission_status');
        });
    }
};
