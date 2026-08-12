<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Eureka e' un'integrazione specifica di ALEX (il tenant master), non di
// ogni partner: le credenziali si spostano da qui (per-tenant, editabili nel
// pannello da chiunque gestisca un tenant) a .env (config/services.php →
// eureka.*), globali per l'intera app — vedi App\Support\Gestionale\EurekaClient
// e Tenant::hasGestionaleEurekaCredentials(). Thread 2026-08-12.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['gestionale_eureka_base_url', 'gestionale_eureka_username', 'gestionale_eureka_password']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('gestionale_eureka_base_url')->nullable();
            $table->string('gestionale_eureka_username')->nullable();
            $table->text('gestionale_eureka_password')->nullable();
        });
    }
};
