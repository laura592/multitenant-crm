<?php

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Console\Command;

/**
 * Importa il listino "04 GLOBAL 5G EUR fully automatics ENG_2025_V1.0.pdf"
 * (valido dal 01.01.2025 al 31.12.2025): aggiunge, per ogni SKU Franke gia'
 * presente a catalogo, una riga di prezzo con quella validita' storica,
 * cosi' come stampata nel listino (nessun ricalcolo). Il PDF viene anche
 * allegato in price_lists (fornitore Franke), come gia' fatto per Classic
 * A Line 2026 e Nuova A Line 2026.
 *
 * La corrispondenza SKU <-> riga del PDF 2025 e' stata determinata per nome
 * e, dove il nome da solo non basta a distinguere le varianti con lo stesso
 * nome (es. "S1 steam wand" a 170 o 265 secondo la macchina), verificando
 * che il prezzo di vendita 2026 gia' a catalogo sia coerente con lo stesso
 * rapporto (~1,887-1,90x) osservato su tutte le altre righe.
 *
 * Due articoli del PDF (FSU60 CM Flavor Station per SB1200 UT40, prima e
 * seconda macchina) non esistevano affatto a catalogo: creati come nuovi
 * prodotti con solo il prezzo 2025 (nessun prezzo 2026 noto).
 *
 * Idempotente: updateOrCreate su (product_id, valid_from, valid_to).
 */
class ImportFrankeGlobal2025PriceList extends Command
{
    protected $signature = 'import:franke-global-2025';

    protected $description = 'Importa il listino Franke GLOBAL 5G EUR fully automatics 2025 (documento + prezzi storici 2025)';

    protected const VALID_FROM = '2025-01-01';

    protected const VALID_TO = '2025-12-31';

    protected const PDF_RELATIVE_PATH = 'price-lists/global-5g-eur-fully-automatics-2025-v1.pdf';

    /**
     * [sku => prezzo 2025]. Un valore ripetuto per piu' SKU e' corretto: nel
     * PDF lo stesso importo compare identico su piu' modelli/opzioni.
     */
    protected const PRICES = [
        // A300 W3
        'A300-NM-1G-H1-W3' => 2550,
        'A300-NM-1G-2P-H1-W3' => 2779,
        'A300-MS-EC-1G-H1-W3' => 2865,
        'A300-FM-EC-1G-H1-W3' => 3390,
        // A300 W4
        'A300-NM-1G-H1-W4' => 2695,
        'A300-NM-1G-2P-H1-W4' => 2924,
        'A300-MS-EC-1G-H1-W4' => 3010,
        'A300-FM-EC-1G-H1-W4' => 3535,
        'SU03-EC' => 620,
        // Opzioni A300 (comuni a W3/W4)
        'OPT-2G-570' => 300,
        'OPT-P1-455' => 240,
        'OPT-P2-910' => 480,
        'OPT-EXT-GRANI' => 54,
        'OPT-S1-320' => 170,
        'OPT-LOCK-GRANI' => 145,
        'OPT-PIED-40' => 56,
        'OPT-PIED-REG-160' => 84,
        'OPT-SCARICO' => 102,
        'OPT-IOT-4G' => 219,
        'LIC-FC-MANAGE' => 550,
        'OPT-PIED-40-SU03' => 18,
        'OPT-PIED-REG-SU03' => 28,

        // A400
        'A400-NM-1G-H1' => 3360,
        'A400-MS-EC-1G-H1' => 3655,
        'A400-FM-CM-1G-H1' => 3655,
        'KE200-EC' => 520,
        'SU05-EC' => 1190,
        'SU12-EC' => 1860,
        'SU05-CM' => 2745,
        'OPT-2G-690' => 365,
        'OPT-DP-765' => 405,
        'OPT-2DP' => 405,
        'OPT-FS' => 89,
        'OPT-S1-500' => 265,
        'OPT-MON-TAZZE' => 150,
        'OPT-PIED-REG-53' => 28,
        'OPT-COLL-SERB' => 265,
        'ACC-CW' => 785,
        'ACC-MON-LATTE' => 180,

        // A600
        'A600-1G-H1' => 4375,
        'A600-MS-EC-1G-H1' => 4680,
        'A600-FM-EC-1G-H1' => 5665,
        'A600-FM-EC-MU-1G-H1' => 5145,
        'SU12-EC-TWIN' => 1860,
        'UC09-EC' => 1510,
        'MU-EC' => 1670,
        'OPT-IQFLOW' => 315,
        'OPT-IC' => 505,
        'OPT-S2' => 405,
        'OPT-S3' => 500,
        'OPT-2LATTE' => 270,
        'OPT-DMI' => 32,
        'OPT-SS-MON' => 290,
        'OPT-RIC-OTT' => 210,
        'OPT-SS-OTT' => 350,
        'OPT-SERB-4L' => 265,
        'ACC-FS30' => 1950,
        'ACC-FSU30' => 1345,

        // A600 FM CM
        'A600-FM-CM-1G-H1' => 4680,

        // A800
        'A800-1G-H1' => 6295,
        'A800-FM-EC-1G-H1' => 6950,
        'A800-FM-EC-MU-1G-H1' => 6440,
        'OPT-2G-835' => 440,
        'OPT-3G' => 440,
        'OPT-BRICCO' => 305,

        // A1000 FM CM
        'A1000-FM-CM-1G-H1' => 8080,
        'SU12-CM' => 2885,
        'SU12-CM-TWIN' => 3895,
        'ACC-FS60' => 2420,
        'ACC-FSU60' => 1815,

        // S700
        'S700-2G-H1-S2' => 6970,
        'OPT-S3-UPG' => 95,

        // SB1200 FM CM SU12
        'SB1200-FM-CM-2G-SU12-2OM' => 13305,
        'OPT-IMI' => 510,
        'OPT-RIC-OTT-115' => 60,
        'OPT-GUARN' => 28,

        // SB1200 FM CM UT40
        'SB1200-FM-CM-2G-UT40-CM-1OM' => 12795,
        'SB1200-FM-CM-2G-UT40-CM-2OM-IMI' => 14300,
        'OPT-OM' => 995,
        'OPT-DM' => 295,

        // SB1200 FM CM UT40 Twin
        '2X-SB1200-FM-CM-2G-UT40-CM-2OM' => 22960,
        '2X-SB1200-FM-CM-2G-UT40-CM-3OM-IMI' => 24465,

        // Sistemi di conteggio (solo alloggiamenti generici gia' a catalogo;
        // i lettori per marca da pag. 24-26 non sono ancora a catalogo,
        // vedi import:franke-card-readers per il listino 2026)
        'AC200-STANDARD' => 785,
        'AC125-COMPACT' => 343,
        'AC200-VIP1' => 972,
        'AC125-VIP1' => 530,
        'OPT-CHIAVE-VENDITA' => 130,
        'OPT-GETTONI-100' => 66,
        'SMARTSCHANK' => 1650,
        'SMARTSCHANK-SERR' => 1770,
        'EXT-SERR-CAM' => 420,
        'EXT-CSI' => 400,
        'SERR-CAM-EXT' => 120,
        'CHIAVE-CAM' => 18,
        'STAMP-SCONTRINI' => 350,
        'CAVO-CASSA' => 62,
        'CAVO-MACCHINA' => 62,
        'INT-CSI-RS232' => 760,
        'CAVO-RS232' => 22,

        // A-Line accessories
        '559.0000.000' => 1585,
        '559.0000.002' => 1970,
        '559.0669.328' => 1275,
        'OPT-DISTR-BICCH' => 510,
        'OPT-CESTINO' => 535,
        'OPT-RIPIANO' => 250,
        '560.0539.244' => 430,
        '560.0539.245' => 285,
        '559.0611.422' => 750,
        '559.0615.128' => 435,
    ];

    /**
     * Articoli del PDF non presenti a catalogo: creati come nuovi prodotti,
     * con solo il prezzo 2025 (nessun prezzo 2026 di riferimento esiste).
     */
    protected const NEW_PRODUCTS = [
        'ACC-FSU60-CM' => ['FSU60 CM Flavor Station (sottobanco, per SB1200 UT40)', 2505],
        'ACC-FSU60-CM-2ND' => ['Seconda FSU60 CM Flavor Station (per SB1200 UT40 Twin)', 1925],
    ];

    public function handle(): int
    {
        $this->importDocument();
        $this->importPrices();

        return self::SUCCESS;
    }

    private function importDocument(): void
    {
        $supplier = Supplier::where('name', 'Franke')->first();

        PriceList::updateOrCreate(
            ['name' => 'Franke GLOBAL 5G EUR fully automatics 2025 V1.0'],
            [
                'tenant_id' => null,
                'supplier_id' => $supplier?->id,
                'valid_from' => self::VALID_FROM,
                'valid_to' => self::VALID_TO,
                'file_path' => self::PDF_RELATIVE_PATH,
                'notes' => 'Listino dealer Franke (prezzi IVA esclusa), non il listino di vendita al cliente finale.',
            ]
        );

        $this->info('Documento price_lists creato/aggiornato.');
    }

    private function importPrices(): void
    {
        $updated = 0;
        $missing = [];

        foreach (self::PRICES as $sku => $price) {
            $product = Product::where('sku', $sku)->first();

            if (! $product) {
                $missing[] = $sku;

                continue;
            }

            $product->prices()->updateOrCreate(
                ['valid_from' => self::VALID_FROM, 'valid_to' => self::VALID_TO],
                ['price' => $price]
            );

            $updated++;
        }

        foreach ($missing as $sku) {
            $this->warn("SKU non trovato a catalogo, saltato: {$sku}");
        }

        $category = Product::where('sku', 'ACC-FSU60')->value('category_id');
        $created = 0;

        foreach (self::NEW_PRODUCTS as $sku => [$name, $price]) {
            $product = Product::firstOrCreate(
                ['sku' => $sku],
                [
                    'category_id' => $category,
                    'type' => Product::TYPE_ACCESSORY,
                    'name' => $name,
                    'source' => Product::SOURCE_FRANKE,
                ]
            );

            $product->prices()->updateOrCreate(
                ['valid_from' => self::VALID_FROM, 'valid_to' => self::VALID_TO],
                ['price' => $price]
            );

            $created++;
        }

        $this->info("Prezzi 2025 importati/aggiornati: {$updated}. Nuovi prodotti creati: {$created}.");
    }
}
