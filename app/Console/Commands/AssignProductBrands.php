<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Il brand_id su products e' nullable dalla sua migration (import legacy senza
 * attribuzione nota) e non e' mai stato valorizzato: BrandResource mostra
 * quindi 0 prodotti per ogni brand. Assegna i 210 prodotti del catalogo ai 4
 * brand seedati, verificato incrociando i listini Franke (Classic/New A Line
 * 2026) con i prodotti a source=franke_ufficiale: stesso codici macchina
 * (A300/A400/A600/A800/A1000, S700, SB1200) e unita' (SU/UC/KE, licenze).
 *
 * Idempotente: rieseguibile, si limita a (ri)scrivere il brand_id dei prodotti
 * che rientrano in una delle regole; qualsiasi SKU non riconosciuto resta
 * senza brand e viene segnalato, mai assegnato a caso.
 */
class AssignProductBrands extends Command
{
    protected $signature = 'products:assign-brands';

    protected $description = 'Assegna il brand ai prodotti del catalogo in base a source/SKU';

    /** SKU esatti che non seguono le regole generali per source/prefisso */
    private const SKU_OVERRIDES = [
        '15550' => 'Jura', // WE8
        'LEGACY-232' => 'Jura', // Cool Control 0.6 l (EB) - Jura
        'AAB' => 'Universale/Accessori', // Addolcitore Automatico Balugani
        'installazione' => 'Universale/Accessori',
        'MAT' => 'Universale/Accessori', // Sistema Brita trattamento acqua
    ];

    public function handle(): int
    {
        $brands = Brand::pluck('id', 'name');

        foreach (['Franke', 'Dalla Corte', 'Jura', 'Universale/Accessori'] as $name) {
            if (! $brands->has($name)) {
                $this->error("Brand \"{$name}\" non trovato: esegui prima BrandSeeder.");

                return self::FAILURE;
            }
        }

        $assigned = [];

        Product::where('source', Product::SOURCE_FRANKE)->update(['brand_id' => $brands['Franke']]);
        $assigned[] = Product::where('brand_id', $brands['Franke'])->count().' Franke (source=franke_ufficiale)';

        $dallaCorteCount = Product::where('sku', 'like', 'DC-%')->update(['brand_id' => $brands['Dalla Corte']]);
        $assigned[] = "{$dallaCorteCount} Dalla Corte (SKU DC-*)";

        foreach (self::SKU_OVERRIDES as $sku => $brandName) {
            Product::where('sku', (string) $sku)->update(['brand_id' => $brands[$brandName]]);
        }
        $assigned[] = \count(self::SKU_OVERRIDES).' prodotti via override SKU esatto (Jura/Universale)';

        $this->info('Assegnati: '.implode(', ', $assigned));

        $unassigned = Product::whereNull('brand_id')->pluck('sku', 'name');

        if ($unassigned->isNotEmpty()) {
            $this->warn('Prodotti senza brand (mappatura da rivedere): '.$unassigned->implode(', '));
        } else {
            $this->info('Tutti i prodotti hanno un brand assegnato.');
        }

        return self::SUCCESS;
    }
}
