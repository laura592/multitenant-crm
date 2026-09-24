<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondamenta: utenti, sessioni, code, tenant, permessi e registro attività.
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
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->boolean('is_super_admin')->default(false);
                $table->boolean('is_active')->default(true);
                $table->decimal('daily_contract_hours', 4, 2)->default(8.00);
                $table->decimal('weekly_contract_hours', 5, 2)->default(40.00);
                $table->unsignedInteger('annual_leave_days')->default(26);
                $table->time('default_morning_in')->nullable();
                $table->time('default_morning_out')->nullable();
                $table->time('default_afternoon_in')->nullable();
                $table->time('default_afternoon_out')->nullable();
                $table->string('name');
                $table->string('email');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->string('remember_token', 100)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['email'], 'users_email_unique');
                $table->index(['tenant_id'], 'users_tenant_id_index');
            });
        }

        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->uuid('user_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity');
                $table->index(['last_activity'], 'sessions_last_activity_index');
                $table->index(['user_id'], 'sessions_user_id_index');
            });
        }

        if (! Schema::hasTable('breezy_sessions')) {
            Schema::create('breezy_sessions', function (Blueprint $table) {
                $table->id('id');
                $table->string('authenticatable_type');
                $table->uuid('authenticatable_id');
                $table->string('panel_id')->nullable();
                $table->string('guard')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['authenticatable_type', 'authenticatable_id'], 'breezy_sessions_authenticatable_type_authenticatable_id_index');
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
                $table->index(['expiration'], 'cache_expiration_index');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
                $table->index(['expiration'], 'cache_locks_expiration_index');
            });
        }

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id('id');
                $table->string('queue');
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
                $table->index(['queue'], 'jobs_queue_index');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id('id');
                $table->string('uuid');
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
                $table->unique(['uuid'], 'failed_jobs_uuid_unique');
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->string('notifiable_type');
                $table->uuid('notifiable_id');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable_type_notifiable_id_index');
            });
        }

        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('legal_name')->nullable();
                $table->string('vat_number')->nullable();
                $table->string('tax_code')->nullable();
                $table->string('sdi')->nullable();
                $table->string('iban')->nullable();
                $table->string('email')->nullable();
                $table->json('notify_staff_emails')->nullable();
                $table->json('notify_information_request_emails')->nullable();
                $table->json('notify_leave_request_emails')->nullable();
                $table->json('notify_quote_emails')->nullable();
                $table->json('notify_quote_group_emails')->nullable();
                $table->json('notify_deadline_emails')->nullable();
                $table->json('notify_lavaggio_emails')->nullable();
                $table->json('notify_customer_gestionale_emails')->nullable();
                $table->json('notify_customer_gestionale_review_emails')->nullable();
                $table->json('notify_gestionale_sync_digest_emails')->nullable();
                $table->json('notify_gestionale_sync_failed_emails')->nullable();
                $table->json('notify_service_report_emails')->nullable();
                $table->string('phone')->nullable();
                $table->string('fax')->nullable();
                $table->string('street')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->string('slug');
                $table->boolean('is_master')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('logo_path')->nullable();
                $table->string('primary_color')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->string('client_contact_name')->nullable();
                $table->string('client_contact_phone', 50)->nullable();
                $table->json('notify_quote_response_emails')->nullable();
                $table->index(['is_active'], 'tenants_is_active_index');
                $table->index(['is_master'], 'tenants_is_master_index');
                $table->unique(['slug'], 'tenants_slug_unique');
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['name', 'guard_name'], 'permissions_name_guard_name_unique');
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id('id');
                $table->uuid('tenant_id')->nullable();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['tenant_id'], 'roles_team_foreign_key_index');
                $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_id_name_guard_name_unique');
            });
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->uuid('model_id');
                $table->uuid('tenant_id');
                $table->primary(['tenant_id', 'permission_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->index(['permission_id'], 'model_has_permissions_permission_id_foreign');
                $table->index(['tenant_id'], 'model_has_permissions_team_foreign_key_index');
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->uuid('model_id');
                $table->uuid('tenant_id');
                $table->primary(['tenant_id', 'role_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->index(['role_id'], 'model_has_roles_role_id_foreign');
                $table->index(['tenant_id'], 'model_has_roles_team_foreign_key_index');
            });
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
                $table->index(['role_id'], 'role_has_permissions_role_id_foreign');
            });
        }

        if (! Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
                $table->id('id');
                $table->string('log_name')->nullable();
                $table->text('description');
                $table->string('subject_type')->nullable();
                $table->uuid('subject_id')->nullable();
                $table->string('event')->nullable();
                $table->string('causer_type')->nullable();
                $table->uuid('causer_id')->nullable();
                $table->json('attribute_changes')->nullable();
                $table->json('properties')->nullable();
                $table->uuid('tenant_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['log_name'], 'activity_log_log_name_index');
                $table->index(['tenant_id'], 'activity_log_tenant_id_index');
                $table->index(['causer_type', 'causer_id'], 'causer');
                $table->index(['subject_type', 'subject_id'], 'subject');
            });
        }

        if (! Schema::hasTable('tour_views')) {
            Schema::create('tour_views', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('tenant_id');
                $table->string('page_slug');
                $table->timestamp('viewed_at');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['tenant_id'], 'tour_views_tenant_id_foreign');
                $table->unique(['user_id', 'page_slug'], 'tour_views_user_id_page_slug_unique');
            });
        }

        if (! Schema::hasTable('municipality_postal_codes')) {
            Schema::create('municipality_postal_codes', function (Blueprint $table) {
                $table->id('id');
                $table->string('municipality_name');
                $table->string('province_name');
                $table->string('province_code', 2);
                $table->string('postal_code', 5);
                $table->index(['municipality_name'], 'municipality_postal_codes_municipality_name_index');
                $table->index(['postal_code'], 'municipality_postal_codes_postal_code_index');
            });
        }

        if (! self::haChiave('users', 'users_tenant_id_foreign')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'users_tenant_id_foreign')->references(['id'])->on('tenants')->nullOnDelete();
            });
        }

        if (! self::haChiave('model_has_permissions', 'model_has_permissions_permission_id_foreign')) {
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->foreign(['permission_id'], 'model_has_permissions_permission_id_foreign')->references(['id'])->on('permissions')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('model_has_roles', 'model_has_roles_role_id_foreign')) {
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->foreign(['role_id'], 'model_has_roles_role_id_foreign')->references(['id'])->on('roles')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('role_has_permissions', 'role_has_permissions_permission_id_foreign')) {
            Schema::table('role_has_permissions', function (Blueprint $table) {
                $table->foreign(['permission_id'], 'role_has_permissions_permission_id_foreign')->references(['id'])->on('permissions')->cascadeOnDelete();
                $table->foreign(['role_id'], 'role_has_permissions_role_id_foreign')->references(['id'])->on('roles')->cascadeOnDelete();
            });
        }

        if (! self::haChiave('tour_views', 'tour_views_tenant_id_foreign')) {
            Schema::table('tour_views', function (Blueprint $table) {
                $table->foreign(['tenant_id'], 'tour_views_tenant_id_foreign')->references(['id'])->on('tenants')->cascadeOnDelete();
                $table->foreign(['user_id'], 'tour_views_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('municipality_postal_codes');
        Schema::dropIfExists('tour_views');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('breezy_sessions');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }

    /** La chiave esterna c'e' gia'? Su un database vivo si, su uno nuovo no. */
    private static function haChiave(string $tabella, string $nome): bool
    {
        return collect(Schema::getForeignKeys($tabella))->contains(fn (array $f) => $f['name'] === $nome);
    }
};
