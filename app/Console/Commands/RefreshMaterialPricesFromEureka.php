<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Support\Gestionale\RegistroSync;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;

/**
 * Ricontrolla ogni materiale gia' a catalogo (source=eureka, quindi con un
 * codice reale) via GET /articoli/articolo/(codice) — ricerca esatta, una
 * chiamata per materiale, pooled. Aggiorna solo list_price: type/category/
 * code non vengono toccati qui, per non far driftare l'identita' del
 * materiale (usata da ricerca/matching altrove) da un semplice refresh
 * prezzi. Non scopre materiali nuovi (vedi eureka:import-service-reports
 * per quello) — l'API non offre un elenco completo del catalogo, solo
 * ricerca per codice (thread 2026-08-21).
 */
class RefreshMaterialPricesFromEureka extends Command
{
    protected $signature = 'eureka:refresh-material-prices
        {--tenant= : Slug tenant (default: tenant master)}
        {--dry-run : Mostra cosa cambierebbe senza scrivere nulla}';

    protected $description = 'Aggiorna il prezzo di listino dei materiali gia\' a catalogo dal gestionale Eureka';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $dryRun = (bool) $this->option('dry-run');

        $materials = Material::query()
            ->where('tenant_id', $tenant->id)
            ->where('source', Material::SOURCE_EUREKA)
            ->whereNotNull('code')
            ->get();

        if ($materials->isEmpty()) {
            $this->info('Nessun materiale Eureka a catalogo.');

            return self::SUCCESS;
        }

        $client = new EurekaClient($tenant);

        $paths = $materials->mapWithKeys(fn (Material $material) => [
            $material->id => '/articoli/articolo/'.rawurlencode($material->code),
        ])->all();

        $responses = $client->pooledGetByPath($paths);

        if ($client->hadCompleteFailure()) {
            $this->error('Eureka irraggiungibile: nessun prezzo aggiornato.');

            return self::FAILURE;
        }

        $updated = 0;
        $unchanged = 0;
        $notFound = 0;

        foreach ($materials as $material) {
            $article = $responses[$material->id] ?? [];
            $article = isset($article['id_eureka']) ? $article : ($article[0] ?? null);

            if (! is_array($article) || ! isset($article['prezzo01'])) {
                $notFound++;

                continue;
            }

            $newPrice = round((float) $article['prezzo01'], 2);
            $oldPrice = $material->list_price !== null ? round((float) $material->list_price, 2) : null;

            if ($oldPrice === $newPrice) {
                $unchanged++;

                continue;
            }

            $this->line("{$material->code}: ".($oldPrice ?? '—')." → {$newPrice}");

            if (! $dryRun) {
                $material->update(['list_price' => $newPrice]);

                // Un prezzo che cambia da solo di notte e' la cosa che
                // l'ufficio chiede piu' spesso di ricostruire: va scritto il
                // vecchio accanto al nuovo, non solo il nuovo.
                // Arrotondati: un float grezzo finisce nel log come
                // 13.160000000000000142108547152 ed e' illeggibile proprio
                // dove serve, cioe' quando si cerca quando e' cambiato un
                // prezzo.
                RegistroSync::movimento('prezzi', 'listino aggiornato', [
                    'articolo' => $material->code,
                    'da' => $oldPrice === null ? null : round((float) $oldPrice, 2),
                    'a' => round((float) $newPrice, 2),
                ]);
            }

            $updated++;
        }

        $this->info(sprintf(
            '%sCompletato. Aggiornati: %d, invariati: %d, non trovati su Eureka: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $updated,
            $unchanged,
            $notFound,
        ));

        return self::SUCCESS;
    }
}
