<?php

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Console\Command;

/**
 * Importa il vero listino di vendita 2025 ("37 Italy RRP EUR automatiche
 * ITA_2025_V1.0.pdf", valido 01.01.2025-31.12.2025): questo e', non il
 * GLOBAL 5G EUR (listino dealer), il prezzo da usare nei preventivi -
 * qui non c'e' alcuna distinzione costo/vendita in product_prices, e'
 * sempre e solo il prezzo di vendita (vedi import:franke-global-2025).
 *
 * Corregge quindi le righe 2025-01-01/2025-12-31 scritte da
 * import:franke-global-2025 (che aveva usato per errore gli importi del
 * listino dealer) con gli importi reali di questo listino RRP, e rimuove
 * le poche righe per cui il listino dealer non ha equivalente RRP
 * pulito (vedi RIMUOVI_SENZA_RRP sotto) invece di lasciarci un prezzo di
 * costo travestito da prezzo di vendita.
 *
 * Idempotente: updateOrCreate su (product_id, valid_from, valid_to).
 */
class ImportFrankeItalyRrp2025PriceList extends Command
{
    protected $signature = 'import:franke-italy-rrp-2025';

    protected $description = 'Importa il listino di vendita Franke Italy RRP EUR automatiche 2025 (documento + prezzi 2025 corretti)';

    protected const VALID_FROM = '2025-01-01';

    protected const VALID_TO = '2025-12-31';

    protected const PDF_RELATIVE_PATH = 'price-lists/italy-rrp-eur-automatiche-2025-v1.pdf';

    /**
     * SKU per cui import:franke-global-2025 aveva scritto un prezzo 2025
     * (quello dealer) ma questo listino RRP non offre un valore pulito e
     * comparabile (vedi docblock della classe): la riga 2025-01-01/2025-
     * 12-31 va rimossa invece di lasciare un numero non di vendita.
     */
    protected const RIMUOVI_SENZA_RRP = [
        'OPT-FS', // First Shot: qui e' incluso di serie su A400/A600/A800/A1000, non un'opzione a pagamento
        'LIC-FC-MANAGE', // FrankeCloud Manage (rivenditori/partner): non e' nel listino RRP cliente finale
        'A400-FM-CM-1G-H1', // qui il prezzo e' unico macchina+SU05 CM (12180), non scindibile
        'A600-FM-CM-1G-H1', // idem (14115)
        'SU05-CM', // mai quotata da sola in questo listino (sempre dentro il prezzo bundle FM CM)
    ];

    /**
     * [sku => prezzo di vendita 2025].
     */
    protected const PRICES = [
        // A300 W3
        'A300-NM-1G-H1-W3' => 4840,
        'A300-NM-1G-2P-H1-W3' => 5250,
        'A300-MS-EC-1G-H1-W3' => 5450,
        'A300-FM-EC-1G-H1-W3' => 6450,
        // A300 W4
        'A300-NM-1G-H1-W4' => 5120,
        'A300-NM-1G-2P-H1-W4' => 5530,
        'A300-MS-EC-1G-H1-W4' => 5730,
        'A300-FM-EC-1G-H1-W4' => 6730,
        'SU03-EC' => 1170,
        // Opzioni A300 (comuni a W3/W4)
        'OPT-2G-570' => 575,
        'OPT-P1-455' => 440,
        'OPT-P2-910' => 880,
        'OPT-EXT-GRANI' => 105,
        'OPT-S1-320' => 330,
        'OPT-LOCK-GRANI' => 280,
        'OPT-PIED-40' => 101,
        'OPT-PIED-REG-160' => 152,
        'OPT-SCARICO' => 185,
        'OPT-IOT-4G' => 416,
        'OPT-PIED-40-SU03' => 28,
        'OPT-PIED-REG-SU03' => 51,

        // A400
        'A400-NM-1G-H1' => 6375,
        'A400-MS-EC-1G-H1' => 6935,
        'KE200-EC' => 1030,
        'SU05-EC' => 2265,
        'SU12-EC' => 3525,
        'OPT-2G-690' => 695,
        'OPT-DP-765' => 755,
        'OPT-2DP' => 755,
        'OPT-S1-500' => 505,
        'OPT-MON-TAZZE' => 275,
        'OPT-PIED-REG-53' => 51,
        'OPT-COLL-SERB' => 505,
        'ACC-CW' => 1500,
        'ACC-MON-LATTE' => 360,

        // A600
        'A600-1G-H1' => 8320,
        'A600-MS-EC-1G-H1' => 8880,
        'A600-FM-EC-1G-H1' => 10765,
        'A600-FM-EC-MU-1G-H1' => 9780,
        'SU12-EC-TWIN' => 3525,
        'UC09-EC' => 2865,
        'MU-EC' => 3180,
        'OPT-IQFLOW' => 620,
        'OPT-IC' => 960,
        'OPT-S2' => 755,
        'OPT-S3' => 945,
        'OPT-2LATTE' => 520,
        'OPT-DMI' => 61,
        'OPT-SS-MON' => 550,
        'OPT-RIC-OTT' => 400,
        'OPT-SS-OTT' => 675,
        'OPT-SERB-4L' => 505,
        'ACC-FS30' => 3715,
        'ACC-FSU30' => 2555,

        // A800
        'A800-1G-H1' => 11955,
        'A800-FM-EC-1G-H1' => 13215,
        'A800-FM-EC-MU-1G-H1' => 12240,
        'OPT-2G-835' => 830,
        'OPT-3G' => 830,
        'OPT-BRICCO' => 590,

        // A1000 FM CM
        'A1000-FM-CM-1G-H1' => 15350,
        'SU12-CM' => 5480,
        'SU12-CM-TWIN' => 7400,
        'ACC-FS60' => 4605,
        'ACC-FSU60' => 3445,

        // S700
        'S700-2G-H1-S2' => 13240,
        'OPT-S3-UPG' => 190,

        // SB1200 FM CM SU12
        'SB1200-FM-CM-2G-SU12-2OM' => 25250,
        'OPT-IMI' => 970,
        'OPT-RIC-OTT-115' => 125,
        'OPT-GUARN' => 51,

        // SB1200 FM CM UT40
        'SB1200-FM-CM-2G-UT40-CM-1OM' => 24285,
        'SB1200-FM-CM-2G-UT40-CM-2OM-IMI' => 27145,
        'OPT-OM' => 1890,
        'OPT-DM' => 555,
        'ACC-FSU60-CM' => 4785,

        // SB1200 FM CM UT40 Twin
        '2X-SB1200-FM-CM-2G-UT40-CM-2OM' => 43570,
        '2X-SB1200-FM-CM-2G-UT40-CM-3OM-IMI' => 46430,
        'ACC-FSU60-CM-2ND' => 3670,

        // Sistemi di conteggio A-Line, S700, SB1200 (alloggiamenti generici gia' a catalogo)
        'AC200-STANDARD' => 1572,
        'AC125-COMPACT' => 697,
        'AC200-VIP1' => 1941,
        'AC125-VIP1' => 1066,
        'OPT-CHIAVE-VENDITA' => 255,
        'OPT-GETTONI-100' => 135,
        'SMARTSCHANK' => 3290,
        'SMARTSCHANK-SERR' => 3540,
        'EXT-SERR-CAM' => 830,
        'EXT-CSI' => 785,
        'SERR-CAM-EXT' => 230,
        'CHIAVE-CAM' => 35,
        'STAMP-SCONTRINI' => 710,
        'CAVO-CASSA' => 125,
        'CAVO-MACCHINA' => 125,
        'INT-CSI-RS232' => 1520,
        'CAVO-RS232' => 44,

        // Franke Digital Services (sezione presente solo in questo listino)
        'LIC-FC-MONITOR' => 213,
        'LIC-DIGITAL-SIGNAGE' => 125,
        'LIC-TOUCHLESS' => 125,
        'LIC-MENU-RECIPE' => 125,
        'LIC-CUSTOMIZED-GUI' => 125,
        'LIC-WORKFLOW' => 125,

        // Accessori Linea A
        '559.0000.000' => 3165,
        '559.0000.002' => 3945,
        '559.0669.328' => 2425,
        'OPT-DISTR-BICCH' => 970,
        'OPT-CESTINO' => 1010,
        'OPT-RIPIANO' => 460,
        '560.0539.244' => 860,
        '560.0539.245' => 565,
        '559.0611.422' => 1425,
        '559.0615.128' => 835,
    ];

    public function handle(): int
    {
        $this->importDocument();
        $this->removeStaleCostPrices();
        $this->importPrices();

        return self::SUCCESS;
    }

    private function importDocument(): void
    {
        $supplier = Supplier::where('name', 'Franke')->first();

        PriceList::updateOrCreate(
            ['name' => 'Franke Italy RRP EUR automatiche 2025 V1.0'],
            [
                'tenant_id' => null,
                'supplier_id' => $supplier?->id,
                'valid_from' => self::VALID_FROM,
                'valid_to' => self::VALID_TO,
                'file_path' => self::PDF_RELATIVE_PATH,
                'notes' => 'Listino di vendita (RRP) usato per i preventivi.',
            ]
        );

        $this->info('Documento price_lists creato/aggiornato.');
    }

    private function removeStaleCostPrices(): void
    {
        $removed = 0;

        foreach (self::RIMUOVI_SENZA_RRP as $sku) {
            $product = Product::where('sku', $sku)->first();

            if (! $product) {
                continue;
            }

            $removed += $product->prices()
                ->where('valid_from', self::VALID_FROM)
                ->where('valid_to', self::VALID_TO)
                ->delete();
        }

        $this->info("Righe 2025 senza equivalente RRP rimosse: {$removed}.");
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

        $this->info("Prezzi di vendita 2025 importati/aggiornati: {$updated}.");
    }
}
