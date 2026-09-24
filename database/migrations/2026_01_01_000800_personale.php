<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personale: ore lavorate e richieste di ferie.
 *
 * Una delle otto migration per argomento che hanno sostituito le 158
 * scritte una alla volta (24/09/2026, vedi docs/migrazioni-compattate.md).
 * Ogni tabella si crea solo se non c'e' gia': su un database vivo questa
 * migration non fa niente, su uno nuovo lo costruisce.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('time_entries')) {
            Schema::create('time_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('user_id');
                $table->dateTime('clock_in');
                $table->dateTime('clock_out')->nullable();
                $table->enum('source', ['app', 'manuale'])->default('app');
                $table->uuid('entered_by_user_id')->nullable();
                $table->enum('status', ['aperta', 'chiusa', 'corretta'])->default('aperta');
                $table->boolean('trasferta')->default(false);
                $table->string('destinazione_trasferta')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['clock_in'], 'time_entries_clock_in_index');
                $table->index(['entered_by_user_id'], 'time_entries_entered_by_user_id_foreign');
                $table->index(['tenant_id', 'user_id'], 'time_entries_tenant_id_user_id_index');
                $table->index(['user_id'], 'time_entries_user_id_foreign');
            });
        }

        if (! Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('user_id');
                $table->enum('type', ['ferie', 'permesso', 'malattia']);
                $table->date('date_from');
                $table->date('date_to');
                $table->time('time_from')->nullable();
                $table->time('time_to')->nullable();
                $table->decimal('hours', 5, 2)->nullable();
                $table->enum('status', ['richiesto', 'approvato', 'rifiutato'])->default('richiesto');
                $table->dateTime('requested_at')->nullable();
                $table->uuid('approved_by_user_id')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['approved_by_user_id'], 'leave_requests_approved_by_user_id_foreign');
                $table->index(['status'], 'leave_requests_status_index');
                $table->index(['tenant_id', 'user_id'], 'leave_requests_tenant_id_user_id_index');
                $table->index(['user_id'], 'leave_requests_user_id_foreign');
            });
        }

        if (! self::haChiave('time_entries', 'time_entries_entered_by_user_id_foreign')) {
            Schema::table('time_entries', function (Blueprint $table) {
                $table->foreign(['entered_by_user_id'], 'time_entries_entered_by_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
                $table->foreign(['tenant_id'], 'time_entries_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
                $table->foreign(['user_id'], 'time_entries_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('leave_requests', 'leave_requests_approved_by_user_id_foreign')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->foreign(['approved_by_user_id'], 'leave_requests_approved_by_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
                $table->foreign(['tenant_id'], 'leave_requests_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
                $table->foreign(['user_id'], 'leave_requests_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('time_entries');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
