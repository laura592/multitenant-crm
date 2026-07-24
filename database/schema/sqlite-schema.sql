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
  "machine_discount_percent" numeric not null default '30',
  "default_commission_scenario" varchar check("default_commission_scenario" in('A', 'B', 'C')),
  "scenario_a_commission_percent" numeric not null default '10',
  "scenario_b_installation_fee" numeric not null default '1500',
  "scenario_c_preinstallation_fee" numeric not null default '500',
  "exclusive_supply_required" tinyint(1) not null default '1',
  "territory_exclusive" tinyint(1) not null default '0',
  "territory_notes" text,
  "contract_start_date" date,
  "contract_duration_months" integer not null default '36',
  "notice_period_days" integer not null default '90',
  "saas_billing_enabled" tinyint(1) not null default '0',
  "saas_plan_fee" numeric,
  "saas_billing_cycle" varchar check("saas_billing_cycle" in('monthly', 'annual')),
  "created_at" datetime,
  "updated_at" datetime,
  "sdi" varchar,
  "fax" varchar,
  "notify_staff_emails" text,
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
  "source" varchar not null default 'app',
  "gestionale_code" integer,
  "approved_for_gestionale_at" datetime,
  "sent_to_gestionale_at" datetime,
  "emails" text,
  "phones" text,
  "pec" varchar,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  primary key("id")
);
CREATE INDEX "customers_tenant_id_index" on "customers"("tenant_id");
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
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "quote_groups_tenant_id_number_unique" on "quote_groups"(
  "tenant_id",
  "number"
);
CREATE TABLE IF NOT EXISTS "quotes"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "quote_group_id" varchar,
  "customer_id" varchar not null,
  "number" varchar not null,
  "date" date not null,
  "status" varchar not null default 'bozza',
  "discount" numeric not null default '0',
  "notes" text,
  "payment_method" varchar,
  "subtotal" numeric not null default '0',
  "tax_total" numeric not null default '0',
  "total" numeric not null default '0',
  "commission_scenario" varchar check("commission_scenario" in('A', 'B', 'C')),
  "commission_rate_snapshot" numeric,
  "commission_amount" numeric,
  "commission_direction" varchar check("commission_direction" in('partner_to_master', 'master_to_partner')),
  "commission_status" varchar check("commission_status" in('da_fatturare', 'fatturata', 'pagata')),
  "commission_invoice_number" varchar,
  "commission_invoiced_at" date,
  "commission_due_at" date,
  "commission_paid_at" date,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("quote_group_id") references "quote_groups"("id") on delete set null,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "quotes_tenant_id_number_unique" on "quotes"(
  "tenant_id",
  "number"
);
CREATE INDEX "quotes_customer_id_index" on "quotes"("customer_id");
CREATE INDEX "quotes_commission_status_index" on "quotes"("commission_status");
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
  "handled_by" varchar,
  "handled_by_user_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
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
CREATE TABLE IF NOT EXISTS "comodato_macchine"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar,
  "nome_macchina" varchar not null,
  "costo_macchina" numeric not null,
  "costo_attrezzatura" numeric not null default '0',
  "anni_ammortamento" integer not null,
  "prezzo_annuale_consumabili" numeric not null default '0',
  "costi_manutenzione_annui" numeric not null default '0',
  "costo_caffe_per_battitura" numeric not null default '0',
  "erogazioni_annuali_minime" integer,
  "erogazioni_previste_annue" integer,
  "canone_fisso_annuale" numeric not null default '0',
  "margine_percentuale" numeric not null default '0',
  "note" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "comodato_macchine_tenant_id_index" on "comodato_macchine"(
  "tenant_id"
);
CREATE TABLE IF NOT EXISTS "service_reports"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "number" varchar not null,
  "customer_id" varchar not null,
  "comodato_macchina_id" varchar,
  "quote_id" varchar,
  "machine_product_id" varchar,
  "machine_serial_number" varchar,
  "technician_id" varchar not null,
  "intervention_type" varchar check("intervention_type" in('installazione', 'manutenzione_ordinaria', 'manutenzione_straordinaria', 'riparazione', 'garanzia')) not null,
  "intervention_date" date not null,
  "arrival_at" datetime,
  "departure_at" datetime,
  "problem_description" text,
  "work_performed" text,
  "status" varchar check("status" in('bozza', 'completato', 'firmato', 'inviato')) not null default 'bozza',
  "customer_signature_path" varchar,
  "technician_signature_path" varchar,
  "signed_at" datetime,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  foreign key("comodato_macchina_id") references "comodato_macchine"("id") on delete set null,
  foreign key("quote_id") references "quotes"("id") on delete set null,
  foreign key("machine_product_id") references "products"("id") on delete set null,
  foreign key("technician_id") references "users"("id") on delete restrict,
  primary key("id")
);
CREATE UNIQUE INDEX "service_reports_tenant_id_number_unique" on "service_reports"(
  "tenant_id",
  "number"
);
CREATE INDEX "service_reports_customer_id_index" on "service_reports"(
  "customer_id"
);
CREATE INDEX "service_reports_technician_id_index" on "service_reports"(
  "technician_id"
);
CREATE INDEX "service_reports_intervention_type_index" on "service_reports"(
  "intervention_type"
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
CREATE TABLE IF NOT EXISTS "deadlines"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "deadlinable_type" varchar not null,
  "deadlinable_id" varchar not null,
  "type" varchar check("type" in('assicurazione', 'revisione', 'polizza_rct', 'manutenzione_ordinaria', 'licenza', 'contratto', 'altro')) not null,
  "due_date" date not null,
  "reminder_days_before" integer not null default '30',
  "status" varchar check("status" in('attiva', 'scaduta', 'rinnovata')) not null default 'attiva',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "amount" numeric,
  "paid_at" date,
  "policy_number" varchar,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
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
CREATE TABLE IF NOT EXISTS "maintenance_schedules"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar not null,
  "comodato_macchina_id" varchar,
  "frequency" varchar check("frequency" in('mensile', 'trimestrale', 'semestrale', 'annuale')) not null,
  "last_service_report_id" varchar,
  "next_due_date" date not null,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  foreign key("comodato_macchina_id") references "comodato_macchine"("id") on delete set null,
  foreign key("last_service_report_id") references "service_reports"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "maintenance_schedules_tenant_id_next_due_date_index" on "maintenance_schedules"(
  "tenant_id",
  "next_due_date"
);
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
  "price_delta_override" numeric,
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
CREATE TABLE IF NOT EXISTS "machine_units"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "product_id" varchar,
  "current_customer_id" varchar,
  "serial_number" varchar not null,
  "model_name" varchar,
  "owner_name" varchar not null,
  "status" varchar check("status" in('in_magazzino', 'installata', 'rimossa')) not null default 'in_magazzino',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete set null,
  foreign key("current_customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE UNIQUE INDEX "machine_units_tenant_id_serial_number_unique" on "machine_units"(
  "tenant_id",
  "serial_number"
);
CREATE INDEX "machine_units_current_customer_id_index" on "machine_units"(
  "current_customer_id"
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
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("machine_unit_id") references "machine_units"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "machine_unit_placements_machine_unit_id_placed_at_index" on "machine_unit_placements"(
  "machine_unit_id",
  "placed_at"
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
  "status" varchar check("status" in('bozza', 'inviato', 'ricevuto')) not null default 'bozza',
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
CREATE TABLE IF NOT EXISTS "material_order_emails"(
  "id" varchar not null,
  "material_order_id" varchar not null,
  "user_id" varchar,
  "recipient_email" varchar not null,
  "cc_email" varchar,
  "subject" varchar not null,
  "message" text,
  "status" varchar not null default 'sent',
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("material_order_id") references "material_orders"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "material_order_emails_material_order_id_index" on "material_order_emails"(
  "material_order_id"
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
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("supplier_id") references "suppliers"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "price_lists_tenant_id_index" on "price_lists"("tenant_id");
CREATE INDEX "price_lists_valid_from_valid_to_index" on "price_lists"(
  "valid_from",
  "valid_to"
);
CREATE INDEX "customers_source_index" on "customers"("source");
CREATE UNIQUE INDEX "customers_gestionale_code_unique" on "customers"(
  "gestionale_code"
);
CREATE TABLE IF NOT EXISTS "lavaggi"(
  "id" varchar not null,
  "tenant_id" varchar not null,
  "customer_id" varchar not null,
  "machine_unit_id" varchar,
  "data" date not null,
  "descrizione" varchar not null,
  "note" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  foreign key("machine_unit_id") references "machine_units"("id") on delete set null,
  primary key("id")
);
CREATE INDEX "lavaggi_tenant_id_customer_id_index" on "lavaggi"(
  "tenant_id",
  "customer_id"
);
CREATE INDEX "lavaggi_data_index" on "lavaggi"("data");

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
