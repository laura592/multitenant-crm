<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductOptionSlot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa il catalogo Bianchi Coffee Solutions dal "LISTINO PREZZI 2026"
 * (ED01, valido dal 01/06/2026): macchine Evia/Desia/Talia/Gaia Style
 * Restyling + relativi moduli/accessori, cosi' come per Dalla Corte
 * (RebuildDallaCorteCatalog2026) - stesso schema famiglia -> variante base ->
 * slot "addon" per gli step del wizard ConfigureMachineAction.
 *
 * Idempotente: firstOrCreate per SKU, azzera e ricrea solo gli slot "addon"
 * delle macchine Bianchi ad ogni run.
 */
class ImportBianchiCatalog2026 extends Command
{
    protected $signature = 'bianchi:import-catalog-2026';

    protected $description = 'Importa il catalogo macchine Bianchi Coffee Solutions dal listino prezzi 2026 ED01';

    private const VALID_FROM = '2026-06-01';

    public function handle(): int
    {
        DB::transaction(function () {
            $brandId = Brand::firstOrCreate(['name' => 'Bianchi'])->id;
            $machineCategoryId = Category::firstOrCreate(['name' => 'Macchine Bianchi'])->id;
            $accessoryCategoryId = Category::firstOrCreate(['name' => 'Accessori Bianchi'])->id;

            $families = [
                'Evia' => ProductFamily::firstOrCreate(['name' => 'Bianchi Evia'])->id,
                'Desia' => ProductFamily::firstOrCreate(['name' => 'Bianchi Desia'])->id,
                'Desia Fresh Milk' => ProductFamily::firstOrCreate(['name' => 'Bianchi Desia Fresh Milk'])->id,
                'Talia' => ProductFamily::firstOrCreate(['name' => 'Bianchi Talia'])->id,
                'Gaia Style' => ProductFamily::firstOrCreate(['name' => 'Bianchi Gaia Style Restyling'])->id,
            ];

            $this->createMachines($brandId, $machineCategoryId, $families);
            $this->createAccessories($brandId, $accessoryCategoryId);
            $this->applySlots();
        });

        $this->info('Catalogo Bianchi Coffee Solutions 2026 importato.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $families
     */
    private function createMachines(string $brandId, string $categoryId, array $families): void
    {
        $machines = [
            // Evia
            'EVIAESV01BV' => ['EVIA 2ESV-3 SB MW T10', 7850.00, $families['Evia']],

            // Desia (caffè in grani, senza latte fresco)
            'DESIASES03BV' => ['DESIA S 1ES-2 SB MW T8', 4100.00, $families['Desia']],
            'DESIASESV03BV' => ['DESIA S 1ESV-2 SB MW T8', 4600.00, $families['Desia']],
            'DESIAMES01BV' => ['DESIA M 1ES-3 SB MW T10', 4450.00, $families['Desia']],
            'DESIAMESV01BV' => ['DESIA M 1ESV-3 SB MW T10', 4950.00, $families['Desia']],

            // Desia fresh milk
            'DESIASES02BV' => ['DESIA S 1ES-2 SB MW T8 FM', 5050.00, $families['Desia Fresh Milk']],
            'DESIASESV02BV' => ['DESIA S 1ESV-2 SB MW T8 FM', 5600.00, $families['Desia Fresh Milk']],
            'DESIAMES02BV' => ['DESIA M 1ES-3 SB MW T10 FM', 5400.00, $families['Desia Fresh Milk']],
            'DESIAMESV02BV' => ['DESIA M 1ESV-3 SB MW T10 FM', 5950.00, $families['Desia Fresh Milk']],

            // Talia easy
            'TALIAES01BV' => ['TALIA 1ES-3 SB MW EASY STD', 3350.00, $families['Talia']],
            'TALIAESV01BV' => ['TALIA 1ESV-3 SB MW EASY STD', 3800.00, $families['Talia']],
            'TALIAES03BV' => ['TALIA 1ES-3 DB MW EASY FV', 3400.00, $families['Talia']],
            'TALIAIN01BV' => ['TALIA IN-5 SB MW EASY STD', 2800.00, $families['Talia']],
            // Talia touch
            'TALIAES02BV' => ['TALIA 1ES-3 SB MW T7 STD', 3700.00, $families['Talia']],
            'TALIAESV02BV' => ['TALIA 1ESV-3 SB MW T7 STD', 4150.00, $families['Talia']],
            'TALIAES04BV' => ['TALIA 1ES-3 DB MW T7 FV', 3700.00, $families['Talia']],
            'TALIAESV03BV' => ['TALIA 1ESV-3 SB MW T7 FV', 4150.00, $families['Talia']],

            // Gaia Style easy
            'GAIARYSES04BV' => ['GAIA STYLE RY 1ES-2 SB MW EASY FV', 2700.00, $families['Gaia Style']],
            'GAIARYSES03BV' => ['GAIA STYLE RY 1ES-2 DB MW EASY FV', 2800.00, $families['Gaia Style']],
            'GAIARYSIN02BV' => ['GAIA STYLE RY IN-3 SB MW EASY FV', 2400.00, $families['Gaia Style']],
            'GAIARYSES02BV' => ['GAIA STYLE RY 1ES-2 SB WT EASY FV', 2700.00, $families['Gaia Style']],
            'GAIARYSIN01BV' => ['GAIA STYLE RY IN-3 SB WT EASY FV', 2400.00, $families['Gaia Style']],
            // Gaia Style touch
            'GAIARYTES04BV' => ['GAIA STYLE RY 1ES-2 SB MW T7 FV', 3100.00, $families['Gaia Style']],
            'GAIARYTES03BV' => ['GAIA STYLE RY 1ES-2 DB MW T7 FV', 3200.00, $families['Gaia Style']],
            'GAIARYTIN02BV' => ['GAIA STYLE RY IN-3 SB MW T7 FV', 2800.00, $families['Gaia Style']],
            'GAIARYTESV03BV' => ['GAIA STYLE RY 1ESV-2 DB MW T7 FV HORECA', 3750.00, $families['Gaia Style']],
            'GAIARYTES02BV' => ['GAIA STYLE RY 1ES-2 SB WT T7 FV', 3100.00, $families['Gaia Style']],
        ];

        foreach ($machines as $sku => [$name, $price, $familyId]) {
            $this->upsertProduct($sku, $name, Product::TYPE_MACHINE, $categoryId, $brandId, $familyId, $price);
        }
    }

    private function createAccessories(string $brandId, string $categoryId): void
    {
        $accessories = [
            // Moduli Evia
            'EVIAMOD13BV' => 'Modulo scaldatazze (Evia)|1000.00',
            'EVIAMOD16BV' => 'Modulo cappuccinatore con termoblocco (Evia)|2050.00',
            'EVIAMOD17BV' => 'Modulo cappuccinatore con termoblocco e frigorifero (Evia)|2800.00',
            'EVIAMOD14BV' => 'Modulo sistemi di pagamento (Evia)|800.00',
            // Accessori Evia
            '41114830' => 'Kit pompa ad immersione per Lei300 EVO/EVIA/DESIA|60.00',
            '41120720' => 'Kit scarico fondi caffè diretto EVIA|145.00',
            '41120610' => 'Kit scarico liquidi diretto EVIA (inclusi piedini rialzati)|80.00',

            // Frigorifero Desia fresh milk
            'FRIDGE01' => 'Frigorifero con porta nera (Desia)|600.00',
            'FRIDGE02' => 'Frigorifero con porta trasparente (Desia)|600.00',
            // Accessori Desia
            '41134310' => 'Kit scarico fondi caffè e liquidi DESIA M|180.00',
            '41134410' => 'Kit scarico fondi caffè e liquidi DESIA S|170.00',
            '41133510' => 'Kit tramoggia caffè in grani maggiorata DESIA|105.00',
            '41133416' => 'Kit IRDA (Desia)|160.00',
            '41132510' => 'BI-PAYPRO (Desia)|300.00',
            'ZO94' => 'Mobiletto DESIA M (incluso kit scarico fondi caffè e liquidi)|790.00',
            'ZO95' => 'Mobiletto DESIA S (incluso kit scarico fondi caffè e liquidi)|780.00',

            // Accessori Talia
            '41114810-01' => 'Kit pompa ad immersione (Talia/Gaia Style)|55.00',
            '41121910-01' => 'Kit sensore presenza bicchiere TALIA|195.00',
            '41075190-01' => 'Kit regolazione automatica macinatura TALIA|170.00',
            '41123120' => 'Kit free vend TALIA|20.00',
            '41123410' => 'Kit scarico fondi caffè e liquidi TALIA|100.00',
            '41088910-03' => 'Kit EXE/MDB per TALIA|65.00',
            'ZO81' => 'Mobiletto TALIA|790.00',

            // Accessori Gaia Style Restyling
            'ZO72' => 'Mobiletto non accessoriato GAIA STYLE RY|400.00',

            // Modulo latte fresco (Talia / Gaia Style, solo versione caffè in grani)
            'FRMI02BL' => 'Modulo latte fresco + porta tazze|2100.00',
        ];

        foreach ($accessories as $sku => $spec) {
            [$name, $price] = explode('|', $spec);
            $this->upsertProduct($sku, $name, Product::TYPE_ACCESSORY, $categoryId, $brandId, null, (float) $price);
        }
    }

    private function upsertProduct(
        string $sku,
        string $name,
        string $type,
        string $categoryId,
        string $brandId,
        ?string $familyId,
        float $price
    ): void {
        $product = Product::firstOrCreate(
            ['sku' => $sku],
            [
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'product_family_id' => $familyId,
                'type' => $type,
                'name' => $name,
                'source' => Product::SOURCE_THIRD_PARTY,
            ]
        );

        // Se il prodotto esisteva gia' (rerun), tiene comunque allineati i
        // metadati anagrafici al listino piu' recente (non tocca i prezzi).
        $product->fill([
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'product_family_id' => $familyId,
            'type' => $type,
            'name' => $name,
            'source' => Product::SOURCE_THIRD_PARTY,
        ])->save();

        if (! $product->getCurrentPrice()) {
            $product->prices()->create(['price' => $price, 'valid_from' => self::VALID_FROM]);
        }
    }

    /**
     * Step "Accessori aggiuntivi" del wizard per ciascuna macchina Bianchi,
     * con gli accessori/moduli davvero compatibili secondo il listino (per
     * Desia S/M e Talia/Gaia caffè in grani vs solubile, vedi tabelle
     * "Accessori" e checkmark Desia S/Desia M del listino).
     */
    private function applySlots(): void
    {
        $eviaAddons = ['EVIAMOD13BV', 'EVIAMOD16BV', 'EVIAMOD17BV', 'EVIAMOD14BV', '41114830', '41120720', '41120610'];
        $this->applyMachineAddons('EVIAESV01BV', $eviaAddons);

        $desiaCommon = ['41114830', '41133510', '41133416', '41132510'];

        $this->applyMachineAddons('DESIASES03BV', [...$desiaCommon, '41134410', 'ZO95']);
        $this->applyMachineAddons('DESIASESV03BV', [...$desiaCommon, '41134410', 'ZO95']);
        $this->applyMachineAddons('DESIAMES01BV', [...$desiaCommon, '41134310', 'ZO94']);
        $this->applyMachineAddons('DESIAMESV01BV', [...$desiaCommon, '41134310', 'ZO94']);

        $this->applyMachineAddons('DESIASES02BV', [...$desiaCommon, '41134410', 'ZO95', 'FRIDGE01', 'FRIDGE02']);
        $this->applyMachineAddons('DESIASESV02BV', [...$desiaCommon, '41134410', 'ZO95', 'FRIDGE01', 'FRIDGE02']);
        $this->applyMachineAddons('DESIAMES02BV', [...$desiaCommon, '41134310', 'ZO94', 'FRIDGE01', 'FRIDGE02']);
        $this->applyMachineAddons('DESIAMESV02BV', [...$desiaCommon, '41134310', 'ZO94', 'FRIDGE01', 'FRIDGE02']);

        $taliaAccessories = ['41114810-01', '41121910-01', '41075190-01', '41123120', '41123410', '41088910-03', 'ZO81'];

        // Talia caffè in grani: accessori + modulo latte fresco.
        foreach (['TALIAES01BV', 'TALIAESV01BV', 'TALIAES03BV', 'TALIAES02BV', 'TALIAESV02BV', 'TALIAES04BV', 'TALIAESV03BV'] as $sku) {
            $this->applyMachineAddons($sku, [...$taliaAccessories, 'FRMI02BL']);
        }
        // Talia caffè solubile: nessun modulo latte fresco (incompatibile).
        $this->applyMachineAddons('TALIAIN01BV', $taliaAccessories);

        $gaiaAccessories = ['41114810-01', 'ZO72'];

        // Gaia Style caffè in grani: accessori + modulo latte fresco.
        foreach (['GAIARYSES04BV', 'GAIARYSES03BV', 'GAIARYSES02BV', 'GAIARYTES04BV', 'GAIARYTES03BV', 'GAIARYTESV03BV', 'GAIARYTES02BV'] as $sku) {
            $this->applyMachineAddons($sku, [...$gaiaAccessories, 'FRMI02BL']);
        }
        // Gaia Style caffè solubile: nessun modulo latte fresco.
        foreach (['GAIARYSIN02BV', 'GAIARYSIN01BV', 'GAIARYTIN02BV'] as $sku) {
            $this->applyMachineAddons($sku, $gaiaAccessories);
        }
    }

    /**
     * @param  array<int, string>  $accessorySkus
     */
    private function applyMachineAddons(string $machineSku, array $accessorySkus): void
    {
        $machine = Product::where('sku', $machineSku)->first();

        if (! $machine) {
            $this->warn("Macchina non trovata: {$machineSku}");

            return;
        }

        $machine->slots()->where('slot_name', 'addon')->each(fn (ProductOptionSlot $slot) => $slot->delete());

        $slot = ProductOptionSlot::create([
            'product_id' => $machine->id,
            'slot_name' => 'addon',
            'label' => 'Accessori aggiuntivi',
            'min_qty' => 0,
            'max_qty' => null,
            'required' => false,
            'sort_order' => 0,
        ]);

        $sort = 0;
        foreach ($accessorySkus as $accessorySku) {
            $accessory = Product::where('sku', $accessorySku)->first();

            if (! $accessory) {
                $this->warn("Accessorio non trovato: {$accessorySku} (macchina {$machineSku})");

                continue;
            }

            $slot->items()->create([
                'component_product_id' => $accessory->id,
                'price_delta_override' => null,
                'sort_order' => $sort++,
            ]);
        }
    }
}
