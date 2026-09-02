<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\Tenant;
use App\Support\EurekaClient;
use Illuminate\Console\Command;

/**
 * Importa nel catalogo locale gli articoli Eureka mai visti in un
 * rapportino, scorrendo l'INTERO catalogo.
 *
 * Fino al 2026-09-01 questo comando faceva 100 ricerche a due cifre
 * (00-99) su /articoli/lista, nella convinzione che l'API non offrisse un
 * elenco paginato. Quella rotta pero' tronca a 100 risultati senza dire
 * quanti ne esistano: ogni ricerca perdeva in silenzio tutto cio' che
 * stava oltre il centesimo, e i codici senza cifre non venivano trovati
 * mai. Ne mancavano parecchi, fra cui 10067629 GUARNIZIONE SILICONE ROSSO
 * GH1 SPM, segnalato dall'ufficio.
 *
 * /articoli/ricerca invece pagina davvero: vuole un filtro RQL
 * obbligatorio, ma `gt(id,0)` vale "prendi tutto". Vedi
 * EurekaClient::eachArticle(): ora e' un censimento completo, non un
 * campionamento.
 */
class SweepEurekaMaterialsCatalog extends Command
{
    protected $signature = 'eureka:sweep-materials-catalog
        {--tenant= : Slug tenant (default: tenant master)}
        {--dry-run : Mostra quanti materiali nuovi troverebbe senza crearli}';

    protected $description = 'Importa dal catalogo Eureka i materiali non ancora presenti in locale';

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

        $this->info('Lettura del catalogo articoli in corso...');

        $found = [];
        foreach ($this->eureka->eachArticle() as $row) {
            $codice = trim((string) ($row['codice'] ?? ''));

            if ($codice !== '') {
                $found[$codice] = $row;
            }
        }

        $this->info('Articoli letti dal catalogo: '.count($found));

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
