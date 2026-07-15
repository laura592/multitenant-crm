<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Kit di manutenzione preventiva (PM kit) Franke, usati per selezionare i
 * ricambi nei rapportini tecnici (§10). I prezzi sono indicativi in CHF
 * (fonte: catalogo ricambi Franke) - non convertiti, non autorevoli per la
 * fatturazione: servono solo a identificare il kit, non a valorizzarlo.
 */
class ImportMaintenanceKits extends Command
{
    protected $signature = 'import:maintenance-kits';

    protected $description = 'Aggiunge i kit di manutenzione preventiva Franke (per i rapportini tecnici)';

    /**
     * [sku (numero SAP), nome, prezzo CHF indicativo]
     */
    protected const KITS = [
        ['560.0620.875', 'PM kit dosatore polvere SB1200', 73.30],
        ['560.0531.239', 'PM kit A600 B1', 144.62],
        ['560.0008.670', 'PM kit Autosteam Pro', 21.70],
        ['560.0599.679', 'PM kit gruppo erogazione 12 Ø43E', 32.45],
        ['560.0599.685', 'PM kit gruppo erogazione 12 Ø50E', 33.30],
        ['560.0720.822', 'PM kit gruppo erogazione Ø58', 34.53],
        ['560.0531.926', 'PM kit gruppo erogazione 12 Ø50N', 35.00],
        ['560.0599.680', 'PM kit dosatore polvere Linea A', 101.79],
        ['560.0564.733', 'PM kit FS3 (da 06/2019)', 134.91],
        ['560.0542.205', 'PM kit lancia vapore S700', 14.06],
        ['560.0742.964', 'PM kit SU05 CM2 (variante 560.0742.964)', 136.23],
        ['560.0602.292', 'PM kit SU05 CM2 (variante 560.0602.292)', 237.17],
        ['560.0542.989', 'PM kit SU05 FM3 120V', 661.60],
        ['560.0531.558', 'PM kit FS3 (fino a 06/2019)', 138.40],
        ['560.0743.012', 'PM kit MS2', 104.25],
        ['560.0743.011', 'PM kit EC2', 144.06],
        ['560.0720.763', 'PM kit EC2 1M UT', 227.74],
        ['560.0720.766', 'PM kit EC2 2M UT', 318.58],
        ['560.0584.205', 'PM kit A-Line FM EC MU 1', 377.55],
        ['560.0662.812', 'PM kit A-Line FM EC MU 2', 680.09],
    ];

    public function handle(): int
    {
        $category = Category::firstOrCreate(['tenant_id' => null, 'name' => 'Kit Manutenzione Preventiva']);

        $count = 0;

        foreach (self::KITS as [$sku, $name, $priceChf]) {
            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'tenant_id' => null,
                    'category_id' => $category->id,
                    'type' => Product::TYPE_OPTION,
                    'name' => $name,
                    'description' => 'Prezzo indicativo in CHF (fonte catalogo ricambi Franke, non convertito). Usato per i rapportini tecnici, non autorevole per fatturazione.',
                    'source' => Product::SOURCE_FRANKE,
                ]
            );

            $product->prices()->updateOrCreate(
                ['valid_from' => null],
                ['price' => $priceChf]
            );

            $count++;
        }

        $this->info("Kit di manutenzione importati/aggiornati: {$count}");

        return self::SUCCESS;
    }
}
