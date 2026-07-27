<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->string('type')->default('manutenzione')->after('tenant_id');
            $table->unsignedSmallInteger('frequency_days')->nullable()->after('frequency');
            $table->foreignUuid('last_lavaggio_id')->nullable()->after('last_service_report_id')
                ->constrained('lavaggi')->nullOnDelete();

            $table->index(['tenant_id', 'type', 'next_due_date']);
        });

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->enum('frequency', ['mensile', 'trimestrale', 'semestrale', 'annuale'])->nullable()->change();
        });

        Schema::table('lavaggi', function (Blueprint $table) {
            $table->foreignUuid('maintenance_schedule_id')->nullable()
                ->constrained('maintenance_schedules')->nullOnDelete();
        });

        // Backfill: un cliente con una cadenza lavaggi gia' impostata (vecchio
        // sistema su customers) diventa un maintenance_schedule di tipo
        // lavaggio, con i suoi lavaggi storici agganciati al nuovo piano -
        // altrimenti quella configurazione sparirebbe silenziosamente col
        // drop delle colonne qui sotto.
        $customers = DB::table('customers')
            ->whereNotNull('lavaggio_frequency_days')
            ->get(['id', 'tenant_id', 'lavaggio_frequency_days', 'lavaggio_next_due_date']);

        foreach ($customers as $customer) {
            $lastLavaggio = DB::table('lavaggi')
                ->where('customer_id', $customer->id)
                ->orderByDesc('data')
                ->first();

            $nextDueDate = $customer->lavaggio_next_due_date
                ?? ($lastLavaggio
                    ? Carbon::parse($lastLavaggio->data)->addDays($customer->lavaggio_frequency_days)
                    : now()->addDays($customer->lavaggio_frequency_days));

            $scheduleId = (string) Str::orderedUuid();

            DB::table('maintenance_schedules')->insert([
                'id' => $scheduleId,
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'type' => 'lavaggio',
                'frequency_days' => $customer->lavaggio_frequency_days,
                'next_due_date' => $nextDueDate,
                'last_lavaggio_id' => $lastLavaggio->id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('lavaggi')
                ->where('customer_id', $customer->id)
                ->update(['maintenance_schedule_id' => $scheduleId]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['lavaggio_frequency_days', 'lavaggio_next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('lavaggio_frequency_days')->nullable();
            $table->date('lavaggio_next_due_date')->nullable();
        });

        Schema::table('lavaggi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_schedule_id');
        });

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->enum('frequency', ['mensile', 'trimestrale', 'semestrale', 'annuale'])->nullable(false)->change();
        });

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type', 'next_due_date']);
            $table->dropConstrainedForeignId('last_lavaggio_id');
            $table->dropColumn(['type', 'frequency_days']);
        });
    }
};