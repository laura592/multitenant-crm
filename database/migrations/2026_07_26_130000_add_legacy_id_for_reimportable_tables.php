<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `ImportLegacyData` (app/Console/Commands/ImportLegacyData.php) usava
     * Model::create() ovunque: rilanciarlo (es. un run di prova + il run
     * reale in finestra di manutenzione, o un retry dopo un errore) duplicava
     * ogni riga. `legacy_id` (l'id della riga nel DB legacy, connessione
     * "legacy") rende il comando idempotente via updateOrCreate(['legacy_id'
     * => ...], [...]): stesso legacy_id -> stessa riga aggiornata, non
     * duplicata. Vedi piano di cutover, Fase 0.
     */
    public function up(): void
    {
        foreach ([
            'payment_methods',
            'users',
            'categories',
            'products',
            'product_prices',
            'customers',
            'quotes',
            'quote_products',
            'quote_emails',
            'information_requests',
            'comodato_macchine',
        ] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('legacy_id')->nullable()->after('id');
                $blueprint->index('legacy_id');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'payment_methods',
            'users',
            'categories',
            'products',
            'product_prices',
            'customers',
            'quotes',
            'quote_products',
            'quote_emails',
            'information_requests',
            'comodato_macchine',
        ] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex(["{$table}_legacy_id_index"]);
                $blueprint->dropColumn('legacy_id');
            });
        }
    }
};
