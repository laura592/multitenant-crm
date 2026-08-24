<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\Tenant;
use App\Support\EurekaClient;
use Illuminate\Console\Command;

/**
 * L'API Eureka non offre un elenco completo/paginato del catalogo articoli
 * (/articoli/lista/(codice) fa solo ricerca parziale sul codice, capped a
 * 100 risultati, nessuna paginazione — verificato dal vivo 2026-08-21):
 * l'unico modo per scoprire materiali mai referenziati in nessun rapportino
 * importato e' scansionare con tante ricerche diverse e raccogliere i
 * codici unici trovati. Qui si usano tutte le combinazioni a 2 cifre
 * (00-99): sul primo giro (2026-08-21, a mano) ha trovato 2039 codici
 * unici, 1368 mai visti prima nel catalogo locale. Non e' un censimento
 * completo (i codici alfanumerici senza cifre non vengono mai trovati),
 * solo il meglio possibile con l'API disponibile.
 */
class SweepEurekaMaterialsCatalog extends Command
{
    protected $signature = 'eureka:sweep-materials-catalog
        {--tenant= : Slug tenant (default: tenant master)}
        {--dry-run : Mostra quanti materiali nuovi troverebbe senza crearli}';

    protected $description = 'Scansiona il catalogo articoli Eureka con ricerche a 2 cifre per scoprire materiali mai referenziati in un rapportino';

    public function __construct(private readonly EurekaClient $eureka)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $dryRun = (bool) $this->option('dry-run');

        $queries = [];
        foreach (range(0, 99) as $n) {
            $queries[] = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        }

        $this->info('Scansione in corso (100 ricerche pooled, con pause)...');
        $responses = $this->eureka->pooledSearchArticles($queries);

        $found = [];
        foreach ($responses as $rows) {
            foreach ($rows as $row) {
                if (isset($row['codice']) && trim((string) $row['codice']) !== '') {
                    $found[trim((string) $row['codice'])] = $row;
                }
            }
        }

        $this->info('Codici unici trovati: '.count($found));

        $existingCodes = Material::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('code')
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->flip();

        $created = 0;
        $seen = [];

        foreach ($found as $code => $article) {
            $key = strtolower($code);

            if (isset($existingCodes[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($dryRun) {
                $this->line("Nuovo: {$code} — ".($article['descr1'] ?? ''));
                $created++;

                continue;
            }

            Material::create([
                'tenant_id' => $tenant->id,
                'source' => Material::SOURCE_EUREKA,
                'code' => $code,
                'gestionale_code' => $article['id_eureka'] ?? null,
                'list_price' => isset($article['prezzo01']) ? round((float) $article['prezzo01'], 2) : null,
                'category' => 'Eureka',
                'type' => trim((string) ($article['descr1'] ?? '')) ?: 'Materiale Eureka',
            ]);
            $created++;
        }

        $this->info(sprintf(
            '%sCompletato. Materiali nuovi %s: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $dryRun ? 'trovati' : 'creati',
            $created,
        ));

        return self::SUCCESS;
    }
}
