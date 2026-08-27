<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 dello spostamento degli articoli Eureka da Prodotti a Materiali (la
 * fase 1 e' l'import che non li crea piu' li': vedi
 * ImportEurekaServiceReports::resolveExistingProduct()).
 *
 * Fino a qui ogni bene incontrato su un rapportino importato diventava un
 * Product type=service: 189 macchine del parco installato (Faema, Cimbali,
 * impianti alla spina, addolcitori) finite nel catalogo preventivi, dove non
 * sono mai state a listino — 66 delle quali gia' presenti in Materiali con lo
 * stesso codice, perche' eureka:sweep-materials-catalog scandaglia tutto il
 * catalogo articoli, macchine incluse.
 *
 * Qui ognuno di quei prodotti viene ricondotto al suo articolo in Materiali
 * (riusandolo se c'e' gia', creandolo se manca), i rapportini vengono
 * riagganciati li' e il doppione in Prodotti viene eliminato. Un prodotto
 * ancora referenziato da qualcos'altro (un preventivo, uno slot opzioni) non
 * viene toccato: viene segnalato e basta.
 *
 * In coda fa anche il pezzo di fase 3 che si puo' dedurre dai dati:
 * ogni macchinario senza articolo lo eredita dai suoi rapportini, quando questi
 * sono concordi. Idempotente: si puo' rilanciare quante volte si vuole.
 */
class MigrateMachineArticlesToMaterials extends Command
{
    protected $signature = 'eureka:migrate-machine-articles
        {--tenant=  : Slug tenant (default: tenant master)}
        {--dry-run  : Mostra cosa verrebbe fatto senza scrivere nulla}';

    protected $description = 'Sposta in Materiali le macchine create in Prodotti dagli import rapportini, e riaggancia i rapportini';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $dryRun = (bool) $this->option('dry-run');

        // I "prodotti articolo" si riconoscono da type=service +
        // eureka_article_id: nessun altro prodotto a catalogo ha entrambi
        // (verificato sul dump di produzione del 2026-08-27, 189 su 189).
        $products = Product::query()
            ->where('type', Product::TYPE_SERVICE)
            ->whereNotNull('eureka_article_id')
            ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id'))
            ->orderBy('sku')
            ->get();

        // Nessun early return se la lista e' vuota: il comando e' idempotente e
        // la seconda parte (macchinari → articolo) ha senso anche da sola, su un
        // DB dove i prodotti-fantasma sono gia' stati migrati.
        $this->info(sprintf('%sProdotti-articolo da migrare: %d', $dryRun ? '[DRY RUN] ' : '', $products->count()));

        $materialsReused = 0;
        $materialsCreated = 0;
        $reportsMoved = 0;
        $unitsDetached = 0;
        $productsDeleted = 0;
        $productsKept = [];

        foreach ($products as $product) {
            $material = $this->resolveMaterial($tenant, $product, $dryRun, $materialsReused, $materialsCreated);

            $reports = ServiceReport::query()->where('machine_product_id', $product->id)->count();
            $units = MachineUnit::query()->where('product_id', $product->id)->get();

            $blockers = $this->otherReferences($product);

            $this->line(sprintf(
                '  %-22s → %s%s  (rapportini: %d, macchinari: %d)%s',
                $product->sku,
                $material?->code ?? $product->sku,
                $material === null || $material->wasRecentlyCreated ? ' [nuovo]' : '',
                $reports,
                $units->count(),
                $blockers ? "  <comment>[non eliminabile: {$blockers}]</comment>" : '',
            ));

            if ($dryRun || ! $material) {
                $reportsMoved += $reports;
                $unitsDetached += $units->count();

                continue;
            }

            DB::transaction(function () use ($product, $material, $units, $blockers, &$reportsMoved, &$unitsDetached, &$productsDeleted, &$productsKept) {
                // machine_product_id azzerato insieme: il rapportino non deve
                // restare agganciato a un prodotto che sta per sparire, e
                // ServiceReport::gestionaleArticle() legge comunque prima il
                // prodotto e poi il materiale.
                $reportsMoved += ServiceReport::query()
                    ->where('machine_product_id', $product->id)
                    ->update([
                        'machine_material_id' => $material->id,
                        'machine_product_id' => null,
                    ]);

                foreach ($units as $unit) {
                    // Il macchinario passa dal prodotto-fantasma al suo
                    // articolo. model_name resta come rete di sicurezza (e'
                    // cio' che display_name mostra se un giorno l'articolo
                    // sparisse): se era vuoto ci si porta dietro il nome.
                    $unit->update([
                        'material_id' => $unit->material_id ?: $material->id,
                        'model_name' => $unit->model_name ?: $product->name,
                        'product_id' => null,
                    ]);
                    $unitsDetached++;
                }

                if ($blockers) {
                    $productsKept[] = $product->sku.' ('.$blockers.')';

                    return;
                }

                $product->prices()->delete();
                $product->delete();
                $productsDeleted++;
            });
        }

        $linkedFromReports = $this->linkMachineUnitsFromReports($tenant, $dryRun);

        $this->newLine();
        $this->info(sprintf(
            '%sMateriali riusati: %d — creati: %d | rapportini riagganciati: %d | macchinari scollegati: %d | prodotti eliminati: %d',
            $dryRun ? '[DRY RUN] ' : '',
            $materialsReused,
            $materialsCreated,
            $reportsMoved,
            $unitsDetached,
            $productsDeleted,
        ));

        $this->info(sprintf(
            '%sMacchinari agganciati al loro articolo partendo dai rapportini: %d',
            $dryRun ? '[DRY RUN] ' : '',
            $linkedFromReports,
        ));

        if ($productsKept) {
            $this->warn("Prodotti lasciati a catalogo perche' ancora referenziati: ".implode(', ', $productsKept));
        }

        return self::SUCCESS;
    }

    /**
     * Un macchinario senza articolo lo si puo' dedurre dai suoi rapportini:
     * ogni intervento porta con se' l'articolo del bene su cui e' stato fatto.
     * Solo quando i rapportini sono d'accordo fra loro: se su una matricola
     * risultano articoli diversi (matricola riusata, o dato sporco lato
     * gestionale) qui non si indovina, si lascia vuoto.
     */
    private function linkMachineUnitsFromReports(Tenant $tenant, bool $dryRun): int
    {
        $candidates = DB::table('service_reports')
            ->select('machine_unit_id', DB::raw('COUNT(DISTINCT machine_material_id) as articoli'), DB::raw('MIN(machine_material_id) as material_id'))
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('machine_unit_id')
            ->whereNotNull('machine_material_id')
            ->groupBy('machine_unit_id')
            ->having('articoli', '=', 1)
            ->pluck('material_id', 'machine_unit_id');

        if ($candidates->isEmpty()) {
            return 0;
        }

        $units = MachineUnit::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('material_id')
            ->whereIn('id', $candidates->keys())
            ->get();

        if ($dryRun) {
            return $units->count();
        }

        $linked = 0;

        foreach ($units as $unit) {
            $unit->update(['material_id' => $candidates[$unit->id]]);
            $linked++;
        }

        return $linked;
    }

    /**
     * Stessa regola di ImportEurekaServiceReports::resolveOrCreateMaterial():
     * prima il codice Eureka, poi il codice articolo, e solo alla fine si crea.
     */
    private function resolveMaterial(Tenant $tenant, Product $product, bool $dryRun, int &$reused, int &$created): ?Material
    {
        $articleId = (int) $product->eureka_article_id;
        $code = $product->sku;

        $existing = Material::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q
                ->when($articleId > 0, fn ($q) => $q->where('gestionale_code', $articleId))
                ->when(filled($code), fn ($q) => $q->orWhere('code', $code)))
            ->first()
            ?? Material::query()->where('code', $code)->first();

        if ($existing) {
            $reused++;

            // Il materiale trovato per codice puo' non avere ancora il codice
            // Eureka: senza, l'invio a gestionale del rapportino resterebbe
            // bloccato dopo la migrazione (gestionaleValidationErrors()).
            if ($articleId > 0 && blank($existing->gestionale_code) && ! $dryRun) {
                $existing->update(['gestionale_code' => $articleId]);
            }

            return $existing;
        }

        $created++;

        if ($dryRun) {
            return null;
        }

        return Material::create([
            'tenant_id' => $tenant->id,
            'source' => Material::SOURCE_EUREKA,
            'code' => $code ?: 'eureka-'.$articleId,
            'gestionale_code' => $articleId > 0 ? $articleId : null,
            'category' => 'Eureka',
            'type' => $product->name,
        ]);
    }

    /**
     * Tutto cio' che tiene in vita un prodotto oltre ai rapportini e ai
     * macchinari, che questo comando sa gia' spostare.
     */
    private function otherReferences(Product $product): ?string
    {
        $counts = [
            'preventivi' => DB::table('quote_products')->where('product_id', $product->id)->count(),
            'ricambi rapportino' => DB::table('service_report_products')->where('product_id', $product->id)->count(),
            'slot opzioni' => DB::table('product_option_slots')->where('product_id', $product->id)->count()
                + DB::table('product_option_slot_items')->where('component_product_id', $product->id)->count(),
            'requisiti' => DB::table('product_requirements')
                ->where('product_id', $product->id)
                ->orWhere('requires_product_id', $product->id)
                ->count(),
            'esclusioni' => DB::table('product_exclusions')
                ->where('product_id', $product->id)
                ->orWhere('excludes_product_id', $product->id)
                ->count(),
            'richieste informazioni' => DB::table('information_request_product')->where('product_id', $product->id)->count(),
        ];

        $blocking = collect($counts)->filter()->map(fn ($n, $what) => "{$what}: {$n}")->implode(', ');

        return $blocking ?: null;
    }
}
