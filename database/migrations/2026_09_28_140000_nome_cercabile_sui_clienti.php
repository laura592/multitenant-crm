<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una colonna per cercare i clienti senza dover indovinare l'apostrofo
 * (28/09/2026).
 *
 * Eureka scrive gli accenti all'italiana da macchina per scrivere:
 * "Agora'", "Caffe'", "Bistro'", "UNITA'". Sono 259 clienti su 2224, il 12%
 * dell'anagrafica. La ricerca del pannello fa un confronto letterale,
 * quindi digitando "Pra delle Torri" uscivano zero risultati e bisognava
 * sapere dove stava l'apostrofo: e' cosi' che RT-2026-0881 e' finito sul
 * cliente sbagliato.
 *
 * Gli accenti veri invece funzionavano gia': la collation utf8mb4_unicode_ci
 * li ignora nel confronto, quindi "caffe" trova anche "caffe'" scritto con
 * l'accento. Resta solo l'apostrofo da togliere.
 *
 * Una colonna e non una query riscritta: le ricerche cliente nel pannello
 * sono undici, e Filament le costruisce da solo passando l'elenco delle
 * colonne. Aggiungendo questa all'elenco funzionano tutte, comprese quelle
 * che verranno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('search_name')->nullable()->after('company_name')->index();
        });

        // Riempie quelli che ci sono gia'. REPLACE annidati invece di una
        // regex: funziona su MySQL 5.7 come su 8, e qui i caratteri da
        // togliere sono due.
        DB::table('customers')->update([
            'search_name' => DB::raw(
                "TRIM(REPLACE(REPLACE(CONCAT_WS(' ', "
                ."COALESCE(company_name,''), COALESCE(first_name,''), COALESCE(last_name,'')"
                ."), '''', ''), '’', ''))"
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['search_name']);
            $table->dropColumn('search_name');
        });
    }
};
