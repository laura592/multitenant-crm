CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" varchar,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "tenants"(
  "id" varchar not null,
  "name" varchar not null,
  "legal_name" varchar,
  "vat_number" varchar,
  "tax_code" varchar,
  "email" varchar,
  "phone" varchar,
  "street" varchar,
  "postal_code" varchar,
  "city" varchar,
  "province" varchar,
  "slug" varchar not null,
  "is_master" tinyint(1) not null default '0',
  "is_active" tinyint(1) not null default '1',
  "logo_path" varchar,
  "primary_color" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "sdi" varchar,
  "fax" varchar,
  "notify_staff_emails" text,
  "notify_information_request_emails" text,
  "notify_leave_request_emails" text,
  "notify_quote_emails" text,
  "notify_quote_group_emails" text,
  "notify_deadline_emails" text,
  "notify_customer_gestionale_emails" text,
  "notify_customer_gestionale_review_emails" text,
  "notify_gestionale_sync_digest_emails" text,
  "notify_gestionale_sync_failed_emails" text,
  "notify_service_report_emails" text,
  "deleted_at" datetime,
  "iban" varchar,
  "notify_lavaggio_emails" text,
  "client_contact_name" varchar,
  "client_contact_phone" varchar,
  "notify_quote_response_emails" text,
  primary key("id")
);
CREATE INDEX "tenants_is_active_index" on "tenants"("is_active");
CREATE INDEX "tenants_is_master_index" on "tenants"("is_master");
CREATE UNIQUE INDEX "tenants_slug_unique" on "tenants"("slug");
CREATE TABLE IF NOT EXISTS "users"(
  "id" varchar not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "tenant_id" varchar,
  "is_super_admin" tinyint(1) not null default '0',
  "daily_contract_hours" numeric not null default '8',
  "weekly_contract_hours" numeric not null default '40',
  "annual_leave_days" integer not null default '26',
  "is_active" tinyint(1) not null default '1',
  "two_factor_secret" text,
  "two_factor_recovery_codes" text,
  "two_factor_confirmed_at" datetime,
  "default_morning_in" time,
  "default_morning_out" time,
  "default_afternoon_in" time,
  "default_afternoon_out" time,
  foreign key("tenant_id") references "tenants"("id") on delete set null,
  primary key("id")
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE INDEX "users_tenant_id_index" on "users"("tenant_id");
CREATE TABLE IF NOT EXISTS "permissions"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "guard_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "permissions_name_guard_name_unique" on "permissions"(
  "name",
  "guard_name"
);
CREATE TABLE IF NOT EXISTS "roles"(
  "id" integer primary key autoincrement not null,
  "tenant_id" varchar,
  "name" varchar not null,
  "guard_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "roles_team_foreign_key_index" on "roles"("tenant_id");
CREATE UNIQUE INDEX "roles_tenant_id_name_guard_name_unique" on "roles"(
  "tenant_id",
  "name",
  "guard_name"
);
CREATE TABLE IF NOT EXISTS "model_has_permissions"(
  "permission_id" integer not null,
  "model_type" varchar not null,
  "model_id" varchar not null,
  "tenant_id" varchar not null,
  foreign key("permission_id") references "permissions"("id") on delete cascade,
  primary key("tenant_id", "permission_id", "model_id", "model_type")
);
CREATE INDEX "model_has_permissions_model_id_model_type_index" on "model_has_permissions"(
  "model_id",
  "model_type"
);
CREATE INDEX "model_has_permissions_team_foreign_key_index" on "model_has_permissions"(
  "tenant_id"
);
CREATE TABLE IF NOT EXISTS "model_has_roles"(
  "role_id" integer not null,
  "model_type" varchar not null,
  "model_id" varchar not null,
  "tenant_id" varchar not null,
  foreign key("role_id") references "roles"("id") on delete cascade,
  primary key("tenant_id", "role_id", "model_id", "model_type")
);
CREATE INDEX "model_has_roles_model_id_model_type_index" on "model_has_roles"(
  "model_id",
  "model_type"
);
CREATE INDEX "model_has_roles_team_foreign_key_index" on "model_has_roles"(
  "tenant_id"
);
CREATE TABLE IF NOT EXISTS "role_has_permissions"(
  "permission_id" integer not null,
  "role_id" integer not null,
  foreign key("permission_id") references "permissions"("id") on delete cascade,
  foreign key("role_id") references "roles"("id") on delete cascade,
  primary key("permission_id", "role_id")
);
CREATE TABLE IF NOT EXISTS "product_families"(
  "id" varchar not null,
  "tenant_id" varchar,
  "name" varchar not null,
  "description" text,
  "image" varchar,
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "product_families_tenant_id_index" on "product_families"(
  "tenant_id"
);
CREATE TABLE IF NOT EXISTS "product_prices"(
  "id" varchar not null,
  "product_id" varchar not null,
  "price" numeric not null,
  "valid_from" date,
  "valid_to" date,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("product_id") references "products"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "product_prices_product_id_valid_from_valid_to_index" on "product_prices"(
  "product_id",
  "valid_from",
  "valid_to"
);
CREATE TABLE IF NOT EXISTS "product_requirements"(
  "id" varchar not null,
  "product_id" varchar not null,
  "requires_product_id" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("product_id") references "products"("id") on delete cascade,
  foreign key("requires_product_id") references "products"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "product_requirements_unique" on "product_requirements"(
  "product_id",
  "requires_product_id"
);
CREATE TABLE IF NOT EXISTS "product_exclusions"(
  "id" varchar not null,
  "product_id" varchar not null,
  "excludes_product_id" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("product_id") references "products"("id") on delete cascade,
  foreign key("excludes_product_id") references "products"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "product_exclusions_unique" on "product_exclusions"(
  "product_id",
  "excludes_product_id"
);
CREATE TABLE IF NOT EXISTS "payment_methods"(
  "id" varchar not null,
  "name" varchar not null,
  "slug" varchar not null,
  "is_active" tinyint(1) not null default '1',
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE UNIQUE INDEX "payment_methods_slug_unique" on "payment_methods"("slug");
CREATE TABLE IF NOT EXISTS "quote_groups"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar not null,
  "number" varchar not null,
  "status" varchar check("status" in('bozza', 'inviato', 'scelto', 'scaduto')) not null default 'bozza',
  "sent_at" datetime,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "public_token" varchar,
  "client_first_viewed_at" datetime,
  "client_last_viewed_at" datetime,
  "client_view_count" integer not null default '0',
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "quote_groups_tenant_id_number_unique" on "quote_groups"(
  "tenant_id",
  "number"
);
CREATE TABLE IF NOT EXISTS "quote_products"(
  "id" varchar not null,
  "quote_id" varchar not null,
  "product_id" varchar not null,
  "parent_quote_product_id" varchar,
  "quantity" numeric not null default '1',
  "price" numeric not null default '0',
  "discount" integer not null default '0',
  "tax" integer not null default '0',
  "total" numeric not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "contratto_assistenza" varchar,
  foreign key("quote_id") references "quotes"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete restrict,
  foreign key("parent_quote_product_id") references "quote_products"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "quote_products_quote_id_index" on "quote_products"("quote_id");
CREATE INDEX "quote_products_parent_quote_product_id_index" on "quote_products"(
  "parent_quote_product_id"
);
CREATE TABLE IF NOT EXISTS "quote_emails"(
  "id" varchar not null,
  "quote_id" varchar not null,
  "user_id" varchar,
  "recipient_email" varchar not null,
  "cc_email" varchar,
  "subject" varchar not null,
  "message" text,
  "status" varchar not null default 'sent',
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("quote_id") references "quotes"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "quote_emails_quote_id_index" on "quote_emails"("quote_id");
CREATE TABLE IF NOT EXISTS "quote_group_emails"(
  "id" varchar not null,
  "quote_group_id" varchar not null,
  "user_id" varchar,
  "recipient_email" varchar not null,
  "cc_email" varchar,
  "subject" varchar not null,
  "message" text,
  "status" varchar not null default 'sent',
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("quote_group_id") references "quote_groups"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "quote_group_emails_quote_group_id_index" on "quote_group_emails"(
  "quote_group_id"
);
CREATE TABLE IF NOT EXISTS "information_requests"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar not null,
  "number" varchar not null,
  "request_details" text,
  "status" varchar not null default 'nuova',
  "handled_by_user_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "appointment_at" datetime,
  "appointment_notes" text,
  "source" varchar not null default 'crm',
  "origin_url" varchar,
  "raw_payload" text,
  "external_id" varchar,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  foreign key("handled_by_user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE UNIQUE INDEX "information_requests_tenant_id_number_unique" on "information_requests"(
  "tenant_id",
  "number"
);
CREATE INDEX "information_requests_customer_id_index" on "information_requests"(
  "customer_id"
);
CREATE TABLE IF NOT EXISTS "information_request_product"(
  "id" varchar not null,
  "information_request_id" varchar not null,
  "product_id" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("information_request_id") references "information_requests"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete restrict,
  primary key("id")
);
CREATE UNIQUE INDEX "info_request_product_unique" on "information_request_product"(
  "information_request_id",
  "product_id"
);
CREATE TABLE IF NOT EXISTS "service_report_products"(
  "id" varchar not null,
  "service_report_id" varchar not null,
  "product_id" varchar not null,
  "quantity" numeric not null default '1',
  "unit_cost_snapshot" numeric,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("service_report_id") references "service_reports"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete restrict,
  primary key("id")
);
CREATE INDEX "service_report_products_service_report_id_index" on "service_report_products"(
  "service_report_id"
);
CREATE TABLE IF NOT EXISTS "service_report_emails"(
  "id" varchar not null,
  "service_report_id" varchar not null,
  "user_id" varchar,
  "recipient_email" varchar not null,
  "cc_email" varchar,
  "subject" varchar not null,
  "message" text,
  "status" varchar not null default 'sent',
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("service_report_id") references "service_reports"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "service_report_emails_service_report_id_index" on "service_report_emails"(
  "service_report_id"
);
CREATE TABLE IF NOT EXISTS "time_entries"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "user_id" varchar not null,
  "clock_in" datetime not null,
  "clock_out" datetime,
  "source" varchar check("source" in('app', 'manuale')) not null default 'app',
  "entered_by_user_id" varchar,
  "status" varchar check("status" in('aperta', 'chiusa', 'corretta')) not null default 'aperta',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "trasferta" tinyint(1) not null default '0',
  "destinazione_trasferta" varchar,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("entered_by_user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "time_entries_tenant_id_user_id_index" on "time_entries"(
  "tenant_id",
  "user_id"
);
CREATE INDEX "time_entries_clock_in_index" on "time_entries"("clock_in");
CREATE TABLE IF NOT EXISTS "leave_requests"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "user_id" varchar not null,
  "type" varchar check("type" in('ferie', 'permesso', 'malattia')) not null,
  "date_from" date not null,
  "date_to" date not null,
  "hours" numeric,
  "status" varchar check("status" in('richiesto', 'approvato', 'rifiutato')) not null default 'richiesto',
  "requested_at" datetime,
  "approved_by_user_id" varchar,
  "approved_at" datetime,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "time_from" time,
  "time_to" time,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("approved_by_user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "leave_requests_tenant_id_user_id_index" on "leave_requests"(
  "tenant_id",
  "user_id"
);
CREATE INDEX "leave_requests_status_index" on "leave_requests"("status");
CREATE TABLE IF NOT EXISTS "vehicles"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "plate" varchar not null,
  "brand" varchar,
  "model" varchar,
  "year" integer,
  "assigned_user_id" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("assigned_user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "vehicles_tenant_id_index" on "vehicles"("tenant_id");
CREATE TABLE IF NOT EXISTS "brands"(
  "id" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE UNIQUE INDEX "brands_name_unique" on "brands"("name");
CREATE TABLE IF NOT EXISTS "products"(
  "id" varchar not null,
  "tenant_id" varchar,
  "category_id" varchar,
  "product_family_id" varchar,
  "sku" varchar not null,
  "type" varchar not null,
  "name" varchar not null,
  "description" text,
  "image" varchar,
  "source" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "brand_id" varchar,
  "gestionale_code" integer,
  "gestionale_suggested_code" integer,
  "gestionale_suggested_label" varchar,
  "eureka_article_id" integer,
  foreign key("product_family_id") references product_families("id") on delete set null on update no action,
  foreign key("category_id") references categories("id") on delete set null on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("brand_id") references "brands"("id") on delete set null,
  primary key("id")
);
CREATE UNIQUE INDEX "products_sku_unique" on "products"("sku");
CREATE INDEX "products_tenant_id_index" on "products"("tenant_id");
CREATE INDEX "products_type_index" on "products"("type");
CREATE TABLE IF NOT EXISTS "categories"(
  "id" varchar not null,
  "tenant_id" varchar,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "parent_id" varchar,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("parent_id") references "categories"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "categories_tenant_id_index" on "categories"("tenant_id");
CREATE TABLE IF NOT EXISTS "product_option_slots"(
  "id" varchar not null,
  "product_id" varchar not null,
  "slot_name" varchar not null,
  "label" varchar not null,
  "min_qty" integer not null default '0',
  "max_qty" integer,
  "required" tinyint(1) not null default '0',
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("product_id") references "products"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "product_option_slots_product_id_slot_name_unique" on "product_option_slots"(
  "product_id",
  "slot_name"
);
CREATE TABLE IF NOT EXISTS "product_option_slot_items"(
  "id" varchar not null,
  "slot_id" varchar not null,
  "component_product_id" varchar not null,
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("slot_id") references "product_option_slots"("id") on delete cascade,
  foreign key("component_product_id") references "products"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "product_option_slot_items_slot_id_component_product_id_unique" on "product_option_slot_items"(
  "slot_id",
  "component_product_id"
);
CREATE TABLE IF NOT EXISTS "google_calendar_accounts"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "user_id" varchar not null,
  "google_account_email" varchar not null,
  "access_token" text not null,
  "refresh_token" text not null,
  "token_expires_at" datetime,
  "calendar_id" varchar not null,
  "sync_token" varchar,
  "connected_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "google_calendar_accounts_user_id_unique" on "google_calendar_accounts"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "municipality_postal_codes"(
  "id" integer primary key autoincrement not null,
  "municipality_name" varchar not null,
  "province_name" varchar not null,
  "province_code" varchar not null,
  "postal_code" varchar not null
);
CREATE INDEX "municipality_postal_codes_municipality_name_index" on "municipality_postal_codes"(
  "municipality_name"
);
CREATE INDEX "municipality_postal_codes_postal_code_index" on "municipality_postal_codes"(
  "postal_code"
);
CREATE TABLE IF NOT EXISTS "material_order_items"(
  "id" varchar not null,
  "material_order_id" varchar not null,
  "material_id" varchar not null,
  "quantity" integer not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("material_order_id") references "material_orders"("id") on delete cascade,
  foreign key("material_id") references "materials"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "material_order_items_material_order_id_material_id_unique" on "material_order_items"(
  "material_order_id",
  "material_id"
);
CREATE TABLE IF NOT EXISTS "suppliers"(
  "id" varchar not null,
  "tenant_id" varchar,
  "name" varchar not null,
  "address" varchar,
  "postal_code" varchar,
  "city" varchar,
  "province" varchar,
  "phone" varchar,
  "email" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "suppliers_tenant_id_index" on "suppliers"("tenant_id");
CREATE TABLE IF NOT EXISTS "materials"(
  "id" varchar not null,
  "tenant_id" varchar,
  "code" varchar not null,
  "category" varchar not null,
  "type" varchar not null,
  "variant" varchar,
  "tube_diameter" varchar,
  "tube_diameter_2" varchar,
  "thread_size" varchar,
  "thread_type" varchar,
  "barb_diameter" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "supplier_id" varchar,
  "source" varchar not null default 'manuale',
  "gestionale_code" integer,
  "list_price" numeric,
  "maintenance_code" varchar,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("supplier_id") references "suppliers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "materials_category_index" on "materials"("category");
CREATE UNIQUE INDEX "materials_code_unique" on "materials"("code");
CREATE INDEX "materials_tenant_id_index" on "materials"("tenant_id");
CREATE TABLE IF NOT EXISTS "material_orders"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "number" varchar,
  "supplier_id" varchar,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("supplier_id") references "suppliers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "material_orders_tenant_id_index" on "material_orders"(
  "tenant_id"
);
CREATE UNIQUE INDEX "material_orders_tenant_id_number_unique" on "material_orders"(
  "tenant_id",
  "number"
);
CREATE TABLE IF NOT EXISTS "breezy_sessions"(
  "id" integer primary key autoincrement not null,
  "authenticatable_type" varchar not null,
  "authenticatable_id" varchar not null,
  "panel_id" varchar,
  "guard" varchar,
  "ip_address" varchar,
  "user_agent" text,
  "expires_at" datetime,
  "two_factor_secret" text,
  "two_factor_recovery_codes" text,
  "two_factor_confirmed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "breezy_sessions_authenticatable_type_authenticatable_id_index" on "breezy_sessions"(
  "authenticatable_type",
  "authenticatable_id"
);
CREATE TABLE IF NOT EXISTS "activity_log"(
  "id" integer primary key autoincrement not null,
  "log_name" varchar,
  "description" text not null,
  "subject_type" varchar,
  "subject_id" varchar,
  "event" varchar,
  "causer_type" varchar,
  "causer_id" varchar,
  "attribute_changes" text,
  "properties" text,
  "tenant_id" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "subject" on "activity_log"("subject_type", "subject_id");
CREATE INDEX "causer" on "activity_log"("causer_type", "causer_id");
CREATE INDEX "activity_log_log_name_index" on "activity_log"("log_name");
CREATE INDEX "activity_log_tenant_id_index" on "activity_log"("tenant_id");
CREATE TABLE IF NOT EXISTS "notifications"(
  "id" varchar not null,
  "type" varchar not null,
  "notifiable_type" varchar not null,
  "notifiable_id" varchar not null,
  "data" text not null,
  "read_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "notifications_notifiable_type_notifiable_id_index" on "notifications"(
  "notifiable_type",
  "notifiable_id"
);
CREATE TABLE IF NOT EXISTS "price_lists"(
  "id" varchar not null,
  "tenant_id" varchar,
  "supplier_id" varchar,
  "name" varchar not null,
  "valid_from" date,
  "valid_to" date,
  "file_path" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "category" varchar not null default 'listino',
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("supplier_id") references "suppliers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "price_lists_tenant_id_index" on "price_lists"("tenant_id");
CREATE INDEX "price_lists_valid_from_valid_to_index" on "price_lists"(
  "valid_from",
  "valid_to"
);
CREATE TABLE IF NOT EXISTS "deadlines"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "deadlinable_type" varchar not null,
  "deadlinable_id" varchar not null,
  "type" varchar check("type" in('assicurazione', 'bollo', 'revisione', 'polizza_rct', 'manutenzione_ordinaria', 'licenza', 'contratto', 'altro')) not null,
  "due_date" date not null,
  "reminder_days_before" integer not null default('30'),
  "status" varchar not null default('attiva'),
  "created_at" datetime,
  "updated_at" datetime,
  "policy_number" varchar,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  primary key("id")
);
CREATE INDEX "deadlines_deadlinable_type_deadlinable_id_index" on "deadlines"(
  "deadlinable_type",
  "deadlinable_id"
);
CREATE INDEX "deadlines_tenant_id_due_date_index" on "deadlines"(
  "tenant_id",
  "due_date"
);
CREATE INDEX "deadlines_type_index" on "deadlines"("type");
CREATE INDEX "products_eureka_article_id_index" on "products"(
  "eureka_article_id"
);
CREATE INDEX "materials_gestionale_code_index" on "materials"(
  "gestionale_code"
);
CREATE TABLE IF NOT EXISTS "service_report_materials"(
  "id" varchar not null,
  "service_report_id" varchar not null,
  "material_id" varchar not null,
  "quantity" numeric not null default '1',
  "unit_cost_snapshot" numeric,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "line_total_snapshot" numeric,
  foreign key("service_report_id") references "service_reports"("id") on delete cascade,
  foreign key("material_id") references "materials"("id") on delete restrict,
  primary key("id")
);
CREATE INDEX "service_report_materials_service_report_id_index" on "service_report_materials"(
  "service_report_id"
);
CREATE TABLE IF NOT EXISTS "customers"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "first_name" varchar,
  "last_name" varchar,
  "company_name" varchar,
  "street" varchar,
  "postal_code" varchar,
  "city" varchar,
  "province" varchar,
  "tax_code" varchar,
  "vat_number" varchar,
  "sdi" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "latitude" numeric,
  "longitude" numeric,
  "source" varchar not null default('app'),
  "gestionale_code" integer,
  "approved_for_gestionale_at" datetime,
  "sent_to_gestionale_at" datetime,
  "emails" text,
  "phones" text,
  "pec" varchar,
  "website" varchar,
  "website_checked_at" datetime,
  "billing_customer_id" varchar,
  "gestionale_review_flagged_at" datetime,
  "gestionale_review_note" text,
  "gestionale_suggested_code" integer,
  "gestionale_suggested_label" varchar,
  "deleted_at" datetime,
  "consent_privacy_at" datetime,
  "consent_marketing_at" datetime,
  "consent_source" varchar,
  "eureka_note" text,
  foreign key("billing_customer_id") references customers("id") on delete set null on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  primary key("id")
);
CREATE UNIQUE INDEX "customers_gestionale_code_unique" on "customers"(
  "gestionale_code"
);
CREATE INDEX "customers_source_index" on "customers"("source");
CREATE INDEX "customers_tenant_id_index" on "customers"("tenant_id");
CREATE TABLE IF NOT EXISTS "maintenance_schedules"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar not null,
  "frequency" varchar,
  "last_service_report_id" varchar,
  "next_due_date" date,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "type" varchar not null default('manutenzione'),
  "frequency_days" integer,
  "last_lavaggio_id" varchar,
  "status" varchar not null default('attivo'),
  "beverage_type" varchar,
  "filter_validity_days" integer,
  "last_filter_change_id" varchar,
  "machine_unit_id" varchar,
  "lines_count" integer,
  foreign key("last_lavaggio_id") references lavaggi("id") on delete set null on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("customer_id") references customers("id") on delete cascade on update no action,
  foreign key("last_service_report_id") references service_reports("id") on delete set null on update no action,
  foreign key("last_filter_change_id") references lavaggi("id") on delete set null on update no action,
  foreign key("machine_unit_id") references "machine_units"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "maintenance_schedules_tenant_id_next_due_date_index" on "maintenance_schedules"(
  "tenant_id",
  "next_due_date"
);
CREATE INDEX "maintenance_schedules_tenant_id_type_next_due_date_index" on "maintenance_schedules"(
  "tenant_id",
  "type",
  "next_due_date"
);
CREATE TABLE IF NOT EXISTS "lavaggi"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar not null,
  "machine_unit_id" varchar,
  "data" date not null,
  "descrizione" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "maintenance_schedule_id" varchar,
  "filtro_sostituito" tinyint(1) not null default('0'),
  "service_report_id" varchar,
  "lines_washed" integer,
  foreign key("maintenance_schedule_id") references maintenance_schedules("id") on delete set null on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("customer_id") references customers("id") on delete cascade on update no action,
  foreign key("machine_unit_id") references machine_units("id") on delete set null on update no action,
  foreign key("service_report_id") references "service_reports"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "lavaggi_data_index" on "lavaggi"("data");
CREATE INDEX "lavaggi_tenant_id_customer_id_index" on "lavaggi"(
  "tenant_id",
  "customer_id"
);
CREATE UNIQUE INDEX "lavaggi_service_report_id_maintenance_schedule_id_unique" on "lavaggi"(
  "service_report_id",
  "maintenance_schedule_id"
);
CREATE TABLE IF NOT EXISTS "service_report_maintenance_schedule"(
  "service_report_id" varchar not null,
  "maintenance_schedule_id" varchar not null,
  foreign key("service_report_id") references "service_reports"("id") on delete cascade,
  foreign key("maintenance_schedule_id") references "maintenance_schedules"("id") on delete cascade,
  primary key("service_report_id", "maintenance_schedule_id")
);
CREATE TABLE IF NOT EXISTS "information_request_notes"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "information_request_id" varchar not null,
  "logged_at" date not null,
  "body" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("information_request_id") references "information_requests"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "information_request_notes_information_request_id_index" on "information_request_notes"(
  "information_request_id"
);
CREATE TABLE IF NOT EXISTS "tour_views"(
  "id" varchar not null,
  "user_id" varchar not null,
  "tenant_id" varchar not null,
  "page_slug" varchar not null,
  "viewed_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "tour_views_user_id_page_slug_unique" on "tour_views"(
  "user_id",
  "page_slug"
);
CREATE UNIQUE INDEX "information_requests_tenant_id_external_id_unique" on "information_requests"(
  "tenant_id",
  "external_id"
);
CREATE TABLE IF NOT EXISTS "quotes"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "quote_group_id" varchar,
  "customer_id" varchar not null,
  "number" varchar not null,
  "date" date not null,
  "status" varchar not null default('bozza'),
  "discount" numeric not null default('0'),
  "notes" text,
  "payment_method" varchar,
  "subtotal" numeric not null default('0'),
  "tax_total" numeric not null default('0'),
  "total" numeric not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "rental_monthly_fee" numeric,
  "rental_months" integer,
  "deleted_at" datetime,
  "information_request_id" varchar,
  "billing_customer_id" varchar,
  "extra_discount" numeric not null default '0',
  "public_token" varchar,
  "client_first_viewed_at" datetime,
  "client_last_viewed_at" datetime,
  "client_view_count" integer not null default '0',
  foreign key("information_request_id") references information_requests("id") on delete set null on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("quote_group_id") references quote_groups("id") on delete set null on update no action,
  foreign key("customer_id") references customers("id") on delete cascade on update no action,
  foreign key("billing_customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "quotes_customer_id_index" on "quotes"("customer_id");
CREATE UNIQUE INDEX "quotes_tenant_id_number_unique" on "quotes"(
  "tenant_id",
  "number"
);
CREATE TABLE IF NOT EXISTS "eureka_partite_aperte"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "tipo" varchar not null,
  "gestionale_code" integer not null,
  "customer_id" varchar,
  "ragione_sociale" varchar,
  "anno" integer not null,
  "numero_fattura" varchar,
  "data_fattura" date,
  "data_scadenza" date,
  "saldo" numeric not null,
  "created_at" datetime,
  "updated_at" datetime,
  "tipo_pagamento" varchar,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "eureka_partite_aperte_tenant_id_tipo_index" on "eureka_partite_aperte"(
  "tenant_id",
  "tipo"
);
CREATE INDEX "eureka_partite_aperte_tenant_id_data_scadenza_index" on "eureka_partite_aperte"(
  "tenant_id",
  "data_scadenza"
);
CREATE INDEX "eureka_partite_aperte_customer_id_index" on "eureka_partite_aperte"(
  "customer_id"
);
CREATE TABLE IF NOT EXISTS "eureka_fatture"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "tipo" varchar not null,
  "id_eureka" integer not null,
  "gestionale_code" integer,
  "customer_id" varchar,
  "ragione_sociale" varchar,
  "partita_iva" varchar,
  "numero_doc" varchar,
  "data_doc" date,
  "totale_doc" numeric not null default '0',
  "imponibile" numeric not null default '0',
  "pagamento" varchar,
  "causale" varchar,
  "id_b10_origine" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "e_acconto" tinyint(1) not null default '0',
  "detrae_acconto_numero" varchar,
  "detrazione_ambigua" tinyint(1) not null default '0',
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE UNIQUE INDEX "eureka_fatture_tenant_id_tipo_id_eureka_unique" on "eureka_fatture"(
  "tenant_id",
  "tipo",
  "id_eureka"
);
CREATE INDEX "eureka_fatture_tenant_id_tipo_data_doc_index" on "eureka_fatture"(
  "tenant_id",
  "tipo",
  "data_doc"
);
CREATE INDEX "eureka_fatture_customer_id_index" on "eureka_fatture"(
  "customer_id"
);
CREATE TABLE IF NOT EXISTS "eureka_saldi_anagrafiche"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "tipo" varchar not null,
  "gestionale_code" integer not null,
  "ragione_sociale" varchar,
  "saldo" numeric not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "eureka_saldi_anagrafiche_tenant_id_tipo_gestionale_code_unique" on "eureka_saldi_anagrafiche"(
  "tenant_id",
  "tipo",
  "gestionale_code"
);
CREATE TABLE IF NOT EXISTS "eureka_fatturato_mesi"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "tipo" varchar not null,
  "anno" integer not null,
  "mese" integer not null,
  "dare" numeric not null default '0',
  "avere" numeric not null default '0',
  "netto" numeric not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "eureka_fatturato_mesi_tenant_id_tipo_anno_mese_unique" on "eureka_fatturato_mesi"(
  "tenant_id",
  "tipo",
  "anno",
  "mese"
);
CREATE TABLE IF NOT EXISTS "eureka_cashflow_mesi"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "anno" integer not null,
  "mese" integer not null,
  "entrate" numeric not null default '0',
  "uscite" numeric not null default '0',
  "entrate_ftc" numeric not null default '0',
  "entrate_oc" numeric not null default '0',
  "entrate_bc" numeric not null default '0',
  "uscite_ftf" numeric not null default '0',
  "uscite_of" numeric not null default '0',
  "uscite_bf" numeric not null default '0',
  "saldo_mese" numeric not null default '0',
  "saldo_progressivo" numeric not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "eureka_cashflow_mesi_tenant_id_anno_mese_unique" on "eureka_cashflow_mesi"(
  "tenant_id",
  "anno",
  "mese"
);
CREATE TABLE IF NOT EXISTS "eureka_cashflow_voci"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "anno" integer not null,
  "mese" integer not null,
  "data_documento" date,
  "data_scadenza" date,
  "numero" varchar,
  "descrizione" varchar,
  "tipo" varchar,
  "importo_totale" numeric not null default '0',
  "importo" numeric not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "eureka_cashflow_voci_tenant_id_anno_mese_index" on "eureka_cashflow_voci"(
  "tenant_id",
  "anno",
  "mese"
);
CREATE TABLE IF NOT EXISTS "prodotti_caffe"(
  "id" varchar not null,
  "gruppo" varchar not null,
  "nome" varchar not null,
  "formato" varchar,
  "prezzo" numeric not null,
  "ordinamento" integer not null default '0',
  "attivo" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "offerte_caffe"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar not null,
  "user_id" varchar,
  "number" varchar not null,
  "date" date not null,
  "valida_fino" date,
  "righe" text not null,
  "note" text,
  "status" varchar not null default 'bozza',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "offerte_caffe_tenant_id_number_index" on "offerte_caffe"(
  "tenant_id",
  "number"
);
CREATE TABLE IF NOT EXISTS "offerta_caffe_emails"(
  "id" varchar not null,
  "offerta_caffe_id" varchar not null,
  "user_id" varchar,
  "inviata_con" varchar,
  "recipient_email" varchar not null,
  "cc_email" varchar,
  "subject" varchar,
  "message" text,
  "status" varchar not null default 'sent',
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("offerta_caffe_id") references "offerte_caffe"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "price_lists_category_index" on "price_lists"("category");
CREATE UNIQUE INDEX "quotes_public_token_unique" on "quotes"("public_token");
CREATE UNIQUE INDEX "quote_groups_public_token_unique" on "quote_groups"(
  "public_token"
);
CREATE TABLE IF NOT EXISTS "quote_responses"(
  "id" varchar not null,
  "tenant_id" varchar,
  "quote_id" varchar,
  "quote_group_id" varchar,
  "type" varchar not null,
  "signer_name" varchar,
  "signer_role" varchar,
  "email" varchar,
  "phone" varchar,
  "preferred_time" varchar,
  "reason" varchar,
  "message" text,
  "signature_path" varchar,
  "accepted_pdf_path" varchar,
  "accepted_pdf_sha256" varchar,
  "ip_address" varchar,
  "user_agent" varchar,
  "handled_at" datetime,
  "handled_by" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete set null,
  foreign key("quote_id") references "quotes"("id") on delete set null,
  foreign key("quote_group_id") references "quote_groups"("id") on delete set null,
  foreign key("handled_by") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "quote_responses_type_handled_at_index" on "quote_responses"(
  "type",
  "handled_at"
);
CREATE TABLE IF NOT EXISTS "machine_unit_placements"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "machine_unit_id" varchar not null,
  "customer_id" varchar,
  "placed_at" datetime not null,
  "removed_at" datetime,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "billing_customer_id" varchar,
  "eureka_billing_customer_code" integer,
  foreign key("customer_id") references customers("id") on delete set null on update no action,
  foreign key("machine_unit_id") references machine_units("id") on delete cascade on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("billing_customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "machine_unit_placements_machine_unit_id_placed_at_index" on "machine_unit_placements"(
  "machine_unit_id",
  "placed_at"
);
CREATE TABLE IF NOT EXISTS "esecuzioni_eureka"(
  "id" integer primary key autoincrement not null,
  "comando" varchar not null,
  "avviata_il" datetime not null,
  "finita_il" datetime,
  "esito" varchar not null default 'in_corso',
  "riepilogo" text,
  "errore" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "esecuzioni_eureka_comando_avviata_il_index" on "esecuzioni_eureka"(
  "comando",
  "avviata_il"
);
CREATE INDEX "esecuzioni_eureka_comando_index" on "esecuzioni_eureka"(
  "comando"
);
CREATE TABLE IF NOT EXISTS "service_reports"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "number" varchar not null,
  "customer_id" varchar not null,
  "quote_id" varchar,
  "machine_product_id" varchar,
  "machine_serial_number" varchar,
  "technician_id" varchar not null,
  "intervention_type" varchar not null,
  "intervention_date" date not null,
  "arrival_at" datetime,
  "departure_at" datetime,
  "problem_description" text,
  "work_performed" text,
  "status" varchar not null default('bozza'),
  "customer_signature_path" varchar,
  "technician_signature_path" varchar,
  "signed_at" datetime,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "machine_unit_id" varchar,
  "gestionale_scheda_lavoro_id" integer,
  "gestionale_sync_status" varchar,
  "gestionale_sync_error" text,
  "gestionale_synced_at" datetime,
  "eureka_service_report_id" integer,
  "customer_signature_name" varchar,
  "deleted_at" datetime,
  "source" varchar not null default('manuale'),
  "gestionale_number" varchar,
  "gestionale_document_date" date,
  "eureka_destinazione_code" integer,
  "eureka_destinazione_label" varchar,
  "eureka_stato_documento" integer,
  "eureka_stato_label" varchar,
  "machine_material_id" varchar,
  "billing_customer_id" varchar,
  "lavaggio_vie_count" integer,
  "duplicato_suggerito_id" varchar,
  "duplicato_suggerito_motivo" varchar,
  "eureka_fatture" text,
  "eureka_fatturato_il" date,
  "eureka_fatture_controllate_il" datetime,
  "eureka_fattura_motivo" varchar,
  "eureka_fattura_indizio" varchar,
  "visita_id" varchar,
  "pagante_fattura_customer_id" varchar,
  "pagante_fattura_rilevato_il" datetime,
  "pagante_fattura_ok" varchar,
  foreign key("duplicato_suggerito_id") references service_reports("id") on delete set null on update no action,
  foreign key("machine_material_id") references materials("id") on delete set null on update no action,
  foreign key("machine_unit_id") references machine_units("id") on delete set null on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("customer_id") references customers("id") on delete cascade on update no action,
  foreign key("quote_id") references quotes("id") on delete set null on update no action,
  foreign key("machine_product_id") references products("id") on delete set null on update no action,
  foreign key("technician_id") references users("id") on delete restrict on update no action,
  foreign key("billing_customer_id") references customers("id") on delete set null on update no action,
  foreign key("pagante_fattura_customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "service_reports_customer_id_index" on "service_reports"(
  "customer_id"
);
CREATE INDEX "service_reports_eureka_service_report_id_index" on "service_reports"(
  "eureka_service_report_id"
);
CREATE INDEX "service_reports_intervention_type_index" on "service_reports"(
  "intervention_type"
);
CREATE INDEX "service_reports_technician_id_index" on "service_reports"(
  "technician_id"
);
CREATE INDEX "service_reports_tenant_id_eureka_fattura_motivo_index" on "service_reports"(
  "tenant_id",
  "eureka_fattura_motivo"
);
CREATE INDEX "service_reports_tenant_id_eureka_fatturato_il_index" on "service_reports"(
  "tenant_id",
  "eureka_fatturato_il"
);
CREATE UNIQUE INDEX "service_reports_tenant_id_number_unique" on "service_reports"(
  "tenant_id",
  "number"
);
CREATE INDEX "service_reports_visita_id_index" on "service_reports"(
  "visita_id"
);
CREATE INDEX "sr_status_index" on "service_reports"("status");
CREATE INDEX "sr_tenant_data_index" on "service_reports"(
  "tenant_id",
  "intervention_date"
);
CREATE TABLE IF NOT EXISTS "machine_units"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "product_id" varchar,
  "current_customer_id" varchar,
  "serial_number" varchar not null,
  "model_name" varchar,
  "status" varchar not null default('in_magazzino'),
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "billing_customer_id" varchar,
  "gestionale_code" integer,
  "gestionale_suggested_code" integer,
  "gestionale_suggested_label" varchar,
  "source" varchar not null default('manuale'),
  "deleted_at" datetime,
  "type" varchar,
  "eureka_billing_customer_code" integer,
  "material_id" varchar,
  "fusione_suggerita_id" varchar,
  "fusione_suggerita_motivo" varchar,
  "maintenance_code" varchar,
  "spostamento_suggerito_customer_id" varchar,
  "spostamento_suggerito_il" date,
  "spostamento_suggerito_motivo" varchar,
  "spostamento_scartato" varchar,
  "spostamento_suggerito_pagante_code" integer,
  "fusa_in_id" varchar,
  foreign key("spostamento_suggerito_customer_id") references customers("id") on delete set null on update no action,
  foreign key("material_id") references materials("id") on delete set null on update no action,
  foreign key("current_customer_id") references customers("id") on delete set null on update no action,
  foreign key("product_id") references products("id") on delete set null on update no action,
  foreign key("tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("billing_customer_id") references customers("id") on delete set null on update no action,
  foreign key("fusione_suggerita_id") references machine_units("id") on delete set null on update no action,
  foreign key("fusa_in_id") references "machine_units"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "machine_units_billing_customer_id_index" on "machine_units"(
  "billing_customer_id"
);
CREATE INDEX "machine_units_current_customer_id_index" on "machine_units"(
  "current_customer_id"
);
CREATE UNIQUE INDEX "machine_units_tenant_id_serial_number_unique" on "machine_units"(
  "tenant_id",
  "serial_number"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_07_09_100000_create_tenants_table',1);
INSERT INTO migrations VALUES(5,'2026_07_09_100100_add_tenant_fields_to_users_table',1);
INSERT INTO migrations VALUES(6,'2026_07_09_100911_create_permission_tables',1);
INSERT INTO migrations VALUES(7,'2026_07_09_110000_create_catalog_tables',1);
INSERT INTO migrations VALUES(8,'2026_07_09_120000_create_customers_and_quotes_tables',1);
INSERT INTO migrations VALUES(9,'2026_07_09_130000_create_information_requests_and_comodato_tables',1);
INSERT INTO migrations VALUES(10,'2026_07_09_140000_create_service_reports_tables',1);
INSERT INTO migrations VALUES(11,'2026_07_09_150000_create_time_tracking_tables',1);
INSERT INTO migrations VALUES(12,'2026_07_09_160000_create_deadlines_and_maintenance_tables',1);
INSERT INTO migrations VALUES(13,'2026_07_16_010000_create_brands_table',1);
INSERT INTO migrations VALUES(14,'2026_07_16_010100_add_brand_id_to_products_table',1);
INSERT INTO migrations VALUES(15,'2026_07_16_010200_add_parent_id_to_categories_table',1);
INSERT INTO migrations VALUES(16,'2026_07_16_010300_create_product_option_slots_tables',1);
INSERT INTO migrations VALUES(17,'2026_07_16_010400_drop_product_compatibilities_and_option_groups_tables',1);
INSERT INTO migrations VALUES(18,'2026_07_17_010100_create_google_calendar_accounts_table',1);
INSERT INTO migrations VALUES(19,'2026_07_17_142255_create_municipality_postal_codes_table',1);
INSERT INTO migrations VALUES(20,'2026_07_17_163500_add_deadline_dates_to_vehicles_table',1);
INSERT INTO migrations VALUES(21,'2026_07_17_170000_add_coordinates_to_customers_table',1);
INSERT INTO migrations VALUES(22,'2026_07_18_090000_create_machine_units_tables',1);
INSERT INTO migrations VALUES(23,'2026_07_20_064001_create_materials_table',1);
INSERT INTO migrations VALUES(24,'2026_07_20_075823_create_material_orders_tables',1);
INSERT INTO migrations VALUES(25,'2026_07_20_081716_add_number_to_material_orders_table',1);
INSERT INTO migrations VALUES(26,'2026_07_20_090000_create_suppliers_table',1);
INSERT INTO migrations VALUES(27,'2026_07_20_090100_add_supplier_id_to_materials_table',1);
INSERT INTO migrations VALUES(28,'2026_07_20_090200_add_supplier_id_to_material_orders_table',1);
INSERT INTO migrations VALUES(29,'2026_07_20_100000_add_status_to_material_orders_table',1);
INSERT INTO migrations VALUES(30,'2026_07_20_100100_create_material_order_emails_table',1);
INSERT INTO migrations VALUES(31,'2026_07_21_000000_add_is_active_to_users_table',1);
INSERT INTO migrations VALUES(32,'2026_07_21_115550_create_breezy_sessions_table',1);
INSERT INTO migrations VALUES(33,'2026_07_21_134641_create_activity_log_table',1);
INSERT INTO migrations VALUES(34,'2026_07_21_150000_add_company_contact_fields_to_tenants_table',1);
INSERT INTO migrations VALUES(35,'2026_07_21_170000_backfill_tenant_contact_fields_from_defaults',1);
INSERT INTO migrations VALUES(36,'2026_07_21_180000_add_two_factor_columns_to_users_table',1);
INSERT INTO migrations VALUES(37,'2026_07_22_075127_add_amount_and_paid_at_to_deadlines_table',1);
INSERT INTO migrations VALUES(38,'2026_07_22_075150_drop_deadline_dates_from_vehicles_table',1);
INSERT INTO migrations VALUES(39,'2026_07_22_090000_create_notifications_table',1);
INSERT INTO migrations VALUES(40,'2026_07_22_090000_drop_appointments_table',1);
INSERT INTO migrations VALUES(41,'2026_07_22_112611_create_price_lists_table',1);
INSERT INTO migrations VALUES(42,'2026_07_22_132008_add_policy_number_to_deadlines_table',1);
INSERT INTO migrations VALUES(43,'2026_07_22_140000_add_notify_staff_emails_to_tenants_table',1);
INSERT INTO migrations VALUES(44,'2026_07_22_152102_add_source_and_gestionale_sync_to_customers_table',1);
INSERT INTO migrations VALUES(45,'2026_07_22_154706_create_lavaggi_table',1);
INSERT INTO migrations VALUES(46,'2026_07_23_090000_add_multi_contact_fields_to_customers_table',1);
INSERT INTO migrations VALUES(47,'2026_07_23_080917_add_website_fields_to_customers_table',2);
INSERT INTO migrations VALUES(48,'2026_07_23_154200_add_bollo_to_deadlines_type_enum',2);
INSERT INTO migrations VALUES(49,'2026_07_24_090000_add_rental_fields_to_quotes_table',2);
INSERT INTO migrations VALUES(50,'2026_07_24_100000_add_lavaggio_schedule_to_customers_table',2);
INSERT INTO migrations VALUES(51,'2026_07_24_120000_add_billing_customer_id_to_customers_table',2);
INSERT INTO migrations VALUES(52,'2026_07_24_171000_add_notification_recipient_groups_to_tenants_table',2);
INSERT INTO migrations VALUES(53,'2026_07_26_130000_add_legacy_id_for_reimportable_tables',2);
INSERT INTO migrations VALUES(54,'2026_07_27_150000_merge_lavaggio_into_maintenance_schedules',2);
INSERT INTO migrations VALUES(55,'2026_07_27_160000_add_default_shift_times_to_users_table',2);
INSERT INTO migrations VALUES(56,'2026_07_27_170000_add_billing_customer_id_to_machine_units_table',2);
INSERT INTO migrations VALUES(57,'2026_07_27_170100_add_machine_unit_id_to_service_reports_table',2);
INSERT INTO migrations VALUES(58,'2026_07_28_090000_add_gestionale_eureka_credentials_to_tenants_table',2);
INSERT INTO migrations VALUES(59,'2026_07_28_090100_add_gestionale_code_to_products_table',2);
INSERT INTO migrations VALUES(60,'2026_07_28_090200_add_gestionale_sync_to_service_reports_table',2);
INSERT INTO migrations VALUES(61,'2026_07_28_100000_add_notify_deadline_emails_to_tenants_table',2);
INSERT INTO migrations VALUES(62,'2026_07_30_083758_add_status_and_nullable_due_date_to_maintenance_schedules_table',2);
INSERT INTO migrations VALUES(63,'2026_07_30_090000_add_notify_customer_gestionale_emails_to_tenants_table',2);
INSERT INTO migrations VALUES(64,'2026_07_30_090100_add_gestionale_review_to_customers_table',2);
INSERT INTO migrations VALUES(65,'2026_07_30_100000_add_gestionale_suggested_code_to_customers_and_products',2);
INSERT INTO migrations VALUES(66,'2026_07_31_090000_add_gestionale_code_to_machine_units_table',2);
INSERT INTO migrations VALUES(67,'2026_08_02_090000_add_gestionale_suggested_label_to_customers_products_machine_units',2);
INSERT INTO migrations VALUES(68,'2026_08_03_090000_add_beverage_type_to_maintenance_schedules_table',2);
INSERT INTO migrations VALUES(69,'2026_08_03_090100_add_filtro_sostituito_to_lavaggi_table',2);
INSERT INTO migrations VALUES(70,'2026_08_03_150000_create_machine_unit_proposals_table',2);
INSERT INTO migrations VALUES(71,'2026_08_04_090000_add_dismissed_at_to_machine_unit_proposals_table',2);
INSERT INTO migrations VALUES(72,'2026_08_04_090000_add_eureka_ids_to_products_and_machine_units',2);
INSERT INTO migrations VALUES(73,'2026_08_04_090100_add_eureka_service_report_id_to_service_reports',2);
INSERT INTO migrations VALUES(74,'2026_08_04_154532_drop_material_order_emails_table',2);
INSERT INTO migrations VALUES(75,'2026_08_04_154533_drop_status_from_material_orders_table',2);
INSERT INTO migrations VALUES(76,'2026_08_04_160000_add_customer_signature_name_to_service_reports_table',2);
INSERT INTO migrations VALUES(77,'2026_08_05_115551_add_source_and_gestionale_code_to_materials_table',2);
INSERT INTO migrations VALUES(78,'2026_08_05_115551_add_source_to_machine_units_table',2);
INSERT INTO migrations VALUES(79,'2026_08_05_115551_create_service_report_materials_table',2);
INSERT INTO migrations VALUES(80,'2026_08_05_120115_drop_machine_unit_proposals_table',2);
INSERT INTO migrations VALUES(81,'2026_08_05_123944_widen_gestionale_review_note_on_customers_table',2);
INSERT INTO migrations VALUES(82,'2026_08_05_130000_drop_owner_name_from_machine_units_table',2);
INSERT INTO migrations VALUES(83,'2026_08_05_161236_clean_rtf_from_service_report_notes',2);
INSERT INTO migrations VALUES(84,'2026_08_06_090000_make_vino_maintenance_schedules_a_chiamata',2);
INSERT INTO migrations VALUES(85,'2026_08_06_100000_replace_comodato_macchina_with_machine_unit_on_maintenance_schedules',2);
INSERT INTO migrations VALUES(86,'2026_08_06_110000_drop_comodato_macchine',2);
INSERT INTO migrations VALUES(87,'2026_08_06_130727_add_lines_count_to_maintenance_schedules_table',2);
INSERT INTO migrations VALUES(88,'2026_08_10_140000_split_gestionale_notification_emails_and_add_service_report',2);
INSERT INTO migrations VALUES(89,'2026_08_10_150000_add_deleted_at_to_soft_deletable_tables',2);
INSERT INTO migrations VALUES(90,'2026_08_10_150000_add_service_report_id_to_lavaggi_table',2);
INSERT INTO migrations VALUES(91,'2026_08_11_090000_add_source_to_service_reports_table',2);
INSERT INTO migrations VALUES(92,'2026_08_11_100000_add_gestionale_number_to_service_reports_table',2);
INSERT INTO migrations VALUES(93,'2026_08_11_101628_drop_unused_legacy_and_eureka_columns',2);
INSERT INTO migrations VALUES(94,'2026_08_12_090000_add_gestionale_document_date_to_service_reports_table',2);
INSERT INTO migrations VALUES(95,'2026_08_12_100000_add_line_total_snapshot_to_service_report_materials_table',2);
INSERT INTO migrations VALUES(96,'2026_08_12_110000_add_iban_to_tenants_table',2);
INSERT INTO migrations VALUES(97,'2026_08_12_110000_add_time_from_to_to_leave_requests_table',2);
INSERT INTO migrations VALUES(98,'2026_08_12_120000_drop_gestionale_eureka_credentials_from_tenants_table',2);
INSERT INTO migrations VALUES(99,'2026_08_12_130000_drop_partner_commercial_fields_from_tenants_table',2);
INSERT INTO migrations VALUES(100,'2026_08_12_130100_drop_commission_fields_from_quotes_table',2);
INSERT INTO migrations VALUES(101,'2026_08_13_090000_drop_note_from_lavaggi_table',2);
INSERT INTO migrations VALUES(102,'2026_08_13_120221_add_sanificazione_to_service_reports_intervention_type',2);
INSERT INTO migrations VALUES(103,'2026_08_13_140000_add_eureka_destinazione_to_service_reports_table',2);
INSERT INTO migrations VALUES(104,'2026_08_17_090000_drop_notes_from_deadlines_table',2);
INSERT INTO migrations VALUES(105,'2026_08_17_090448_add_rifiutato_to_service_reports_status',2);
INSERT INTO migrations VALUES(106,'2026_08_18_081040_create_service_report_maintenance_schedule_table',2);
INSERT INTO migrations VALUES(107,'2026_08_18_120000_drop_amount_and_paid_at_from_deadlines_table',2);
INSERT INTO migrations VALUES(108,'2026_08_19_090000_add_appointment_fields_to_information_requests_table',2);
INSERT INTO migrations VALUES(109,'2026_08_19_100000_create_information_request_notes_table',2);
INSERT INTO migrations VALUES(110,'2026_08_19_110000_add_lines_washed_to_lavaggi_table',2);
INSERT INTO migrations VALUES(111,'2026_08_19_110100_add_type_to_machine_units_table',2);
INSERT INTO migrations VALUES(112,'2026_08_19_123341_remove_price_delta_override_from_product_option_slot_items_table',2);
INSERT INTO migrations VALUES(113,'2026_08_20_140000_add_list_price_to_materials_table',2);
INSERT INTO migrations VALUES(114,'2026_08_24_090000_create_tour_views_table',2);
INSERT INTO migrations VALUES(115,'2026_08_24_140000_add_eureka_stato_documento_to_service_reports_table',2);
INSERT INTO migrations VALUES(116,'2026_08_24_140100_add_eureka_billing_customer_code_to_machine_units_table',2);
INSERT INTO migrations VALUES(117,'2026_08_26_100000_add_lead_intake_fields',2);
INSERT INTO migrations VALUES(118,'2026_08_27_100000_add_machine_material_id_to_service_reports_table',2);
INSERT INTO migrations VALUES(119,'2026_08_27_110000_add_material_id_to_machine_units_table',2);
INSERT INTO migrations VALUES(120,'2026_08_28_120000_add_information_request_id_to_quotes_table',2);
INSERT INTO migrations VALUES(121,'2026_08_31_120000_add_billing_customer_id_to_service_reports_table',2);
INSERT INTO migrations VALUES(122,'2026_08_31_130000_add_in_gestionale_status_to_service_reports_table',2);
INSERT INTO migrations VALUES(123,'2026_08_31_150000_add_billing_customer_id_to_quotes_table',2);
INSERT INTO migrations VALUES(124,'2026_08_31_160000_add_extra_discount_to_quotes_table',2);
INSERT INTO migrations VALUES(125,'2026_08_31_170000_add_sorting_indexes_to_service_reports_table',2);
INSERT INTO migrations VALUES(126,'2026_08_31_180000_add_lavaggio_vie_count_to_service_reports_table',2);
INSERT INTO migrations VALUES(127,'2026_09_01_090000_add_eureka_note_to_customers_table',2);
INSERT INTO migrations VALUES(128,'2026_09_01_100000_create_eureka_partite_aperte_table',2);
INSERT INTO migrations VALUES(129,'2026_09_01_110000_add_notify_lavaggio_emails_to_tenants_table',2);
INSERT INTO migrations VALUES(130,'2026_09_01_110100_seed_notify_lavaggio_emails_for_master_tenant',2);
INSERT INTO migrations VALUES(131,'2026_09_01_160000_add_tipo_pagamento_to_eureka_partite_aperte',2);
INSERT INTO migrations VALUES(132,'2026_09_01_180000_create_eureka_fatture_table',2);
INSERT INTO migrations VALUES(133,'2026_09_01_190000_add_acconto_flags_to_eureka_fatture',2);
INSERT INTO migrations VALUES(134,'2026_09_02_100000_add_detrazione_ambigua_to_eureka_fatture',2);
INSERT INTO migrations VALUES(135,'2026_09_02_110000_create_eureka_saldi_anagrafiche_table',2);
INSERT INTO migrations VALUES(136,'2026_09_02_120000_create_eureka_fatturato_mesi_table',2);
INSERT INTO migrations VALUES(137,'2026_09_02_130000_create_eureka_cashflow_tables',2);
INSERT INTO migrations VALUES(138,'2026_09_02_140000_add_duplicato_suggerito_to_service_reports',2);
INSERT INTO migrations VALUES(139,'2026_09_02_180000_add_fusione_suggerita_to_machine_units',2);
INSERT INTO migrations VALUES(140,'2026_09_04_100000_add_maintenance_code_to_materials_table',2);
INSERT INTO migrations VALUES(141,'2026_09_04_140000_add_maintenance_code_to_machine_units_table',2);
INSERT INTO migrations VALUES(142,'2026_09_21_100000_add_trasferta_to_time_entries_table',2);
INSERT INTO migrations VALUES(143,'2026_09_21_110000_create_prodotti_caffe_table',2);
INSERT INTO migrations VALUES(144,'2026_09_21_120000_add_contratto_assistenza_to_quote_products_table',2);
INSERT INTO migrations VALUES(145,'2026_09_21_130000_lyrae_formato_1kg_in_prodotti_caffe',2);
INSERT INTO migrations VALUES(146,'2026_09_21_140000_add_eureka_fatture_to_service_reports_table',2);
INSERT INTO migrations VALUES(147,'2026_09_21_140000_create_offerte_caffe_table',2);
INSERT INTO migrations VALUES(148,'2026_09_21_150000_cioccolato_formato_500g_in_prodotti_caffe',2);
INSERT INTO migrations VALUES(149,'2026_09_21_160000_add_eureka_fattura_motivo_to_service_reports_table',2);
INSERT INTO migrations VALUES(150,'2026_09_21_170000_add_category_to_price_lists_table',2);
INSERT INTO migrations VALUES(151,'2026_09_21_180000_create_quote_responses_table',2);
INSERT INTO migrations VALUES(152,'2026_09_21_181000_add_client_contact_to_tenants_table',2);
INSERT INTO migrations VALUES(153,'2026_09_22_090000_add_disinstallazione_to_service_reports_intervention_type',2);
INSERT INTO migrations VALUES(154,'2026_09_22_120000_add_spostamento_suggerito_to_machine_units',2);
INSERT INTO migrations VALUES(155,'2026_09_22_140000_add_pagante_to_machine_unit_placements',2);
INSERT INTO migrations VALUES(156,'2026_09_22_160000_add_visita_id_to_service_reports',2);
INSERT INTO migrations VALUES(157,'2026_09_22_180000_create_esecuzioni_eureka_table',2);
INSERT INTO migrations VALUES(158,'2026_09_22_190000_add_controllo_pagante_fattura_to_service_reports',2);
INSERT INTO migrations VALUES(159,'2026_09_22_200000_add_fusa_in_to_machine_units',2);
