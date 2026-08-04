<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Scarta" su una proposta deve solo nasconderla, non cancellarla: se
 * cancelliamo la riga, GestionaleSyncRunner::proposeInstalledMachines() non
 * la vede piu' tra le matricole gia' note e la ripropone al giro successivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_unit_proposals', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('eureka_article_code');
        });
    }

    public function down(): void
    {
        Schema::table('machine_unit_proposals', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
