<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductOptionSlot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Una tantum: il catalogo Dalla Corte era stato popolato con un unico set
 * generico di opzioni "Color"/"Other" copiato su tutte le macchine (Zero+,
 * XT, Icon, Evo2, Mina, Studio...), a prescindere da cosa il listino
 * Dalla Corte 2026 offra davvero per ciascun modello - risultato: opzioni
 * non pertinenti selezionabili (es. "Total Version" su una Zero+, che
 * esiste solo per XT) e prezzi condivisi sbagliati per modello (es. White
 * Oak 1.100€ su Icon, dove nel listino costa 1.250€). DC-MAX inoltre non
 * aveva nessuno slot.
 *
 * Ma il buco piu' grosso era a monte: ConfigureMachineAction (il wizard
 * "famiglia -> variante -> opzioni" usato per Franke) filtra le macchine
 * selezionabili per product_family_id - e NESSUN prodotto Dalla Corte
 * aveva una ProductFamily assegnata, quindi non comparivano proprio nel
 * primo step del wizard, a prescindere da quanto accurati fossero gli
 * slot. Creiamo le famiglie Dalla Corte (una per linea commerciale, es.
 * "Icon" raggruppa 2G/3G/HV) e le assegniamo, cosi' il configuratore
 * funziona per Dalla Corte esattamente come per Franke.
 *
 * Ricostruisce, modello per modello, gli slot con le sole opzioni
 * realmente disponibili per quel modello secondo LISTINO DALLA CORTE 2026.
 * ProductOptionSlotItem.price_delta_override esiste nello schema ma
 * ConfigureMachineAction non lo legge mai (ne' in formatOptionLabel() ne'
 * in createQuoteProducts()): resta scritto ma inerte, quindi dove lo
 * stesso nome opzione ha prezzi diversi a seconda della macchina (es.
 * "White Oak" 1.100€ su XT ma 1.250€ su Icon) usiamo uno SKU dedicato per
 * variante invece di un override che non verrebbe applicato - lo stesso
 * pattern gia' in uso nel catalogo per "Custom RAL (XT)" vs "RAL
 * Personalizzato" di Zero+.
 *
 * Idempotente: azzera e ricrea gli slot ad ogni run (non tocca i
 * quote_products gia' esistenti, che referenziano i Product direttamente
 * e non gli slot).
 */
class RebuildDallaCorteCatalog2026 extends Command
{
    protected $signature = 'dalla-corte:rebuild-catalog-2026';

    protected $description = 'Ricrea famiglie, opzioni e slot di configurazione Dalla Corte secondo il listino 2026';

    public function handle(): int
    {
        DB::transaction(function () {
            $this->ensureEdgeGrinder();
            $this->ensureFamilies();
            $this->ensureOptions();
            $this->rebuildSlots();
        });

        $this->info('Catalogo Dalla Corte 2026 ricostruito.');

        return self::SUCCESS;
    }

    /**
     * "edge" (1.050€, solo Black) manca del tutto dal catalogo attuale.
     */
    private function ensureEdgeGrinder(): void
    {
        $category = Product::where('sku', 'DC-ONE')->value('category_id');

        $edge = Product::firstOrCreate(
            ['sku' => 'DC-EDGE'],
            [
                'category_id' => $category,
                'type' => Product::TYPE_MACHINE,
                'name' => 'Edge',
                'source' => Product::SOURCE_THIRD_PARTY,
            ]
        );

        if (! $edge->getCurrentPrice()) {
            $edge->prices()->create(['price' => 1050.00, 'valid_from' => '2026-08-04']);
        }
    }

    /**
     * Una famiglia per linea commerciale (raggruppa le varianti 2/3/4
     * gruppi e le versioni HV, come i "New A Line" di Franke raggruppano le
     * loro varianti sotto un'unica famiglia).
     *
     * @return array<string, string> nome famiglia => id
     */
    private function ensureFamilies(): array
    {
        $families = [
            'Dalla Corte Zero+ Barista' => ['DC-ZERO-PLUS-2G-BARISTA', 'DC-ZERO-PLUS-3G-BARISTA'],
            'Dalla Corte Zero+ Classic' => ['DC-ZERO-PLUS-2G-CLASSIC', 'DC-ZERO-PLUS-3G-CLASSIC'],
            'Dalla Corte XT Barista' => ['DC-XT-2G-BARISTA', 'DC-XT-3G-BARISTA'],
            'Dalla Corte XT Classic' => ['DC-XT-2G-CLASSIC', 'DC-XT-3G-CLASSIC'],
            'Dalla Corte Icon' => ['DC-ICON-2G', 'DC-ICON-3G', 'DC-ICON-HV-2G', 'DC-ICON-HV-3G'],
            'Dalla Corte Evo2' => ['DC-EVO2-2G', 'DC-EVO2-3G', 'DC-EVO2-4G', 'DC-EVO2-HV-2G', 'DC-EVO2-HV-3G'],
            'Dalla Corte Mina' => ['DC-MINA'],
            'Dalla Corte Studio' => ['DC-STUDIO', 'DC-STUDIO-AQUA'],
            'Macinacaffè Dalla Corte' => ['DC-ONE', 'DC-TWO', 'DC-MAX', 'DC-EDGE'],
        ];

        $ids = [];

        foreach ($families as $name => $skus) {
            $family = ProductFamily::firstOrCreate(['name' => $name]);
            $ids[$name] = $family->id;

            Product::whereIn('sku', $skus)->update(['product_family_id' => $family->id]);
        }

        return $ids;
    }

    /**
     * SKU esistenti gia' a posto (riusati cosi' come sono, prezzo base
     * gia' corretto per il caso piu' comune) + le nuove opzioni che nel
     * catalogo non esistevano affatto (estratte specifiche di Mina,
     * Studio, Studio Aqua e dei macinini).
     *
     */
    private function ensureOptions(): void
    {
        $new = [
            // Mina
            'DC-OPT-MINA-RAL' => ['Custom RAL (Mina)', 500.00],
            'DC-OPT-MINA-GLASS' => ['Glass', 540.00],
            'DC-OPT-MINA-WOOD-VENEER' => ['Wood Veneer', 540.00],
            'DC-OPT-MINA-DARK-OAK' => ['Dark Earth Oak', 540.00],
            'DC-OPT-MINA-LIGHT-OAK' => ['Light Oak', 540.00],
            'DC-OPT-MINA-VENICE-WOOD' => ['Venice Wood', 905.00],
            'DC-OPT-MINA-COFFEERAMA' => ['Coffeerama Gloss', 905.00],
            'DC-OPT-MINA-MCS' => ['MCS Lato Sinistro (Mina)', 280.00],
            // Studio / Studio Aqua
            'DC-OPT-STUDIO-RAL' => ['Custom RAL (Studio)', 450.00],
            'DC-OPT-STUDIO-STEAM-COOL' => ['Lancia Vapore Cool Touch (Studio)', 85.00],
            'DC-OPT-KIT-SCARICO' => ['Kit Scarico Diretto', 125.00],
            // Macinini
            'DC-OPT-GRINDER-RAL-ONE' => ['Custom RAL (dc one)', 800.00],
            'DC-OPT-GRINDER-RAL-TWO' => ['Custom RAL (dc two)', 900.00],
            'DC-OPT-GRINDER-OAK-KIT' => ['Oak Kit', 380.00],
            'DC-OPT-GRINDER-WALNUT-KIT' => ['Walnut Kit', 380.00],
            'DC-OPT-MAX-RAL' => ['Custom RAL (Max)', 350.00],
            'DC-OPT-MAX-GLASS' => ['Glass (Max)', 355.00],
            'DC-OPT-MAX-WOOD-VENEER' => ['Wood Veneer (Max)', 355.00],
            'DC-OPT-MAX-DARK-OAK' => ['Dark Earth Oak (Max)', 355.00],
            'DC-OPT-MAX-LIGHT-OAK' => ['Light Oak (Max)', 355.00],
            'DC-OPT-MAX-VENICE-WOOD' => ['Venice Wood (Max)', 540.00],
            'DC-OPT-MAX-COFFEERAMA' => ['Coffeerama Gloss (Max)', 540.00],
            'DC-OPT-MAX-STAINLESS' => ['Stainless Steel (Max)', 355.00],
            // Varianti con prezzo diverso da quello base condiviso (vedi
            // docblock della classe: niente override, SKU dedicato).
            'DC-OPT-WHITE-OAK-ICON' => ['White Oak (Icon)', 1250.00],
            'DC-OPT-BLACK-WALNUT-ICON' => ['Black Walnut (Icon)', 1250.00],
            'DC-OPT-RAL-TOTAL-ZERO' => ['Total Custom RAL (Zero+)', 2000.00],
        ];

        $category = Product::where('sku', 'DC-OPT-WHITE')->value('category_id');

        foreach ($new as $sku => [$name, $price]) {
            $product = Product::firstOrCreate(
                ['sku' => $sku],
                [
                    'category_id' => $category,
                    'type' => Product::TYPE_OPTION,
                    'name' => $name,
                    'source' => Product::SOURCE_THIRD_PARTY,
                ]
            );

            if (! $product->getCurrentPrice()) {
                $product->prices()->create(['price' => $price, 'valid_from' => '2026-08-04']);
            }
        }

        // Kit Raccordo Idrico esisteva gia' ma senza prezzo (Studio: Kit
        // Allacciamento Idrico 108€).
        $kitIdrico = Product::where('sku', 'DC-OPT-KIT-IDRICO')->first();
        if ($kitIdrico && ! $kitIdrico->getCurrentPrice()) {
            $kitIdrico->prices()->create(['price' => 108.00, 'valid_from' => '2026-08-04']);
        }
    }

    /**
     * I nomi slot ricalcano App\Filament\Actions\ConfigureMachineAction::KNOWN_SLOTS
     * (color/steam/addon/grinder), cosi' Dalla Corte usa esattamente gli
     * stessi step dedicati di Franke nel wizard invece di finire tutto
     * accorpato nel passo generico "Altre opzioni".
     */
    private function rebuildSlots(): void
    {
        // slot_name => ['label' => ..., 'items' => [sku => null, ...]] - il
        // valore e' sempre null (price_delta_override non e' consumato da
        // ConfigureMachineAction, vedi docblock della classe); tenuto solo
        // per coerenza con la firma di applyMachineSlots().
        $zeroPlusExtras = [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-WHITE' => null,
                'DC-OPT-DARK' => null,
                'DC-OPT-RAL-CUSTOM' => null, // 1500
                'DC-OPT-RAL-TOTAL-ZERO' => null, // 2000, dedicato (base condivisa e' 1600)
                'DC-OPT-KIT-ROVERE' => null, // 2500
                'DC-OPT-KIT-NOCE' => null, // 2500
            ]],
            'addon' => ['label' => 'Accessori aggiuntivi', 'items' => [
                'DC-OPT-PF-58-ZERO' => null, // 0, incluso
            ]],
            'grinder' => ['label' => 'Macinacaffè', 'items' => [
                'DC-OPT-MACININO-INSTANT' => null,
            ]],
        ];

        $xtExtras = [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-TOTAL-VERSION' => null, // 350
                'DC-OPT-CUSTOM-RAL-1400' => null, // 1400
                'DC-OPT-RAL-TOTAL' => null, // base 1600 corretto per XT
                'DC-OPT-WHITE-OAK' => null, // base 1100 corretto per XT
                'DC-OPT-DARK-WALNUT' => null, // base 1100
            ]],
            'addon' => ['label' => 'Accessori aggiuntivi', 'items' => [
                'DC-OPT-PF-58' => null, // 160
                'DC-OPT-SPRUZZATORE' => null, // 55
                'DC-OPT-MCS-SINGLE' => null, // 350
            ]],
            'grinder' => ['label' => 'Macinacaffè', 'items' => [
                'DC-OPT-MACININO-INSTANT' => null,
            ]],
        ];

        $iconExtras = [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-DYNAMIC' => null, // 500 "Dynamic Version"
                'DC-OPT-PREMIUM-FEET' => null, // 270
                'DC-OPT-TOTAL-RAL-1000' => null, // 1000
                'DC-OPT-WHITE-OAK-ICON' => null, // 1250, dedicato (base condivisa e' 1100)
                'DC-OPT-BLACK-WALNUT-ICON' => null, // 1250, dedicato (base condivisa e' 1100)
                'DC-OPT-MATT-BLACK-WALNUT' => null, // base 1250 corretto
            ]],
            'steam' => ['label' => 'Lancia vapore', 'items' => [
                'DC-OPT-STEAM-COOL' => null, // 210
                'DC-OPT-STEAM-MAESTRO-S' => null, // 998
                'DC-OPT-STEAM-MAESTRO-D' => null, // 1999
            ]],
            'addon' => ['label' => 'Accessori aggiuntivi', 'items' => [
                'DC-OPT-PF-58' => null, // 160
                'DC-OPT-MCS-SINGLE' => null, // 350
                'DC-OPT-MCS-DOUBLE' => null, // 1900
                'DC-OPT-SPRUZZATORE' => null, // 55
            ]],
            'grinder' => ['label' => 'Macinacaffè', 'items' => [
                'DC-OPT-MACININO-INSTANT' => null,
            ]],
        ];

        $evo2Extras = [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-DYNAMIC-COLOR' => null, // 1200
                'DC-OPT-BLACKBOARD' => null, // 800
                'DC-OPT-RAL-TOTAL' => null, // base 1600 corretto per Evo2
            ]],
            'steam' => ['label' => 'Lancia vapore', 'items' => [
                'DC-OPT-STEAM-COOL' => null, // 210
            ]],
            'addon' => ['label' => 'Accessori aggiuntivi', 'items' => [
                'DC-OPT-PF-58' => null, // 160
                'DC-OPT-MCS-SINGLE' => null, // 350
                'DC-OPT-MCS-DOUBLE' => null, // 1900
                'DC-OPT-SPRUZZATORE' => null, // 55
            ]],
            'grinder' => ['label' => 'Macinacaffè', 'items' => [
                'DC-OPT-MACININO-INSTANT' => null,
            ]],
        ];

        $machines = [
            // Zero+
            'DC-ZERO-PLUS-2G-BARISTA' => [$zeroPlusExtras, ['addon' => ['DC-OPT-CALDAIA-2G' => null]]],
            'DC-ZERO-PLUS-3G-BARISTA' => [$zeroPlusExtras, ['addon' => ['DC-OPT-CALDAIA-3G' => null]]],
            'DC-ZERO-PLUS-2G-CLASSIC' => [$zeroPlusExtras, ['addon' => ['DC-OPT-CALDAIA-2G' => null]]],
            'DC-ZERO-PLUS-3G-CLASSIC' => [$zeroPlusExtras, ['addon' => ['DC-OPT-CALDAIA-3G' => null]]],
            // XT
            'DC-XT-2G-BARISTA' => [$xtExtras, ['addon' => ['DC-OPT-CALDAIA-2G' => null]]],
            'DC-XT-3G-BARISTA' => [$xtExtras, ['addon' => ['DC-OPT-CALDAIA-3G' => null]]],
            'DC-XT-2G-CLASSIC' => [$xtExtras, ['addon' => ['DC-OPT-CALDAIA-2G' => null]]],
            'DC-XT-3G-CLASSIC' => [$xtExtras, ['addon' => ['DC-OPT-CALDAIA-3G' => null]]],
            // Icon / Icon HV
            'DC-ICON-2G' => [$iconExtras, ['addon' => ['DC-OPT-SCALDATAZZE-2G' => null, 'DC-OPT-CALDAIA-2G' => null]]],
            'DC-ICON-3G' => [$iconExtras, ['addon' => ['DC-OPT-SCALDATAZZE-3G' => null, 'DC-OPT-CALDAIA-3G' => null]]],
            'DC-ICON-HV-2G' => [$iconExtras, ['addon' => ['DC-OPT-SCALDATAZZE-2G' => null, 'DC-OPT-CALDAIA-2G' => null]]],
            'DC-ICON-HV-3G' => [$iconExtras, ['addon' => ['DC-OPT-SCALDATAZZE-3G' => null, 'DC-OPT-CALDAIA-3G' => null]]],
            // Evo2
            'DC-EVO2-2G' => [$evo2Extras, ['addon' => ['DC-OPT-SCALDATAZZE-2G' => null, 'DC-OPT-CALDAIA-2G' => null]]],
            'DC-EVO2-3G' => [$evo2Extras, ['addon' => ['DC-OPT-SCALDATAZZE-3G' => null, 'DC-OPT-CALDAIA-3G' => null]]],
            'DC-EVO2-4G' => [$evo2Extras, ['addon' => ['DC-OPT-SCALDATAZZE-3G' => null, 'DC-OPT-CALDAIA-3G' => null]]],
            'DC-EVO2-HV-2G' => [$evo2Extras, ['addon' => ['DC-OPT-SCALDATAZZE-2G' => null, 'DC-OPT-CALDAIA-2G' => null, 'DC-OPT-GRIGLIA-2G' => null]]],
            'DC-EVO2-HV-3G' => [$evo2Extras, ['addon' => ['DC-OPT-SCALDATAZZE-3G' => null, 'DC-OPT-CALDAIA-3G' => null, 'DC-OPT-GRIGLIA-3G' => null]]],
        ];

        foreach ($machines as $sku => [$base, $extra]) {
            $slots = $base;
            foreach ($extra as $slotKey => $items) {
                $slots[$slotKey]['items'] = array_merge($slots[$slotKey]['items'] ?? [], $items);
            }
            $this->applyMachineSlots($sku, $slots);
        }

        // Mina: niente Grinder/Steam (lancia vapore inclusa di serie).
        $this->applyMachineSlots('DC-MINA', [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-MINA-RAL' => null,
                'DC-OPT-MINA-GLASS' => null,
                'DC-OPT-MINA-WOOD-VENEER' => null,
                'DC-OPT-MINA-DARK-OAK' => null,
                'DC-OPT-MINA-LIGHT-OAK' => null,
                'DC-OPT-MINA-VENICE-WOOD' => null,
                'DC-OPT-MINA-COFFEERAMA' => null,
            ]],
            'addon' => ['label' => 'Accessori aggiuntivi', 'items' => [
                'DC-OPT-MINA-MCS' => null,
                'DC-OPT-PF-58' => null,
                'DC-OPT-SPRUZZATORE' => null,
            ]],
        ]);

        // Studio: niente Grinder (macchina manuale a leva).
        $this->applyMachineSlots('DC-STUDIO', [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-STUDIO-RAL' => null,
            ]],
            'steam' => ['label' => 'Lancia vapore', 'items' => [
                'DC-OPT-STUDIO-STEAM-COOL' => null,
            ]],
            'addon' => ['label' => 'Accessori aggiuntivi', 'items' => [
                'DC-OPT-KIT-IDRICO' => null,
                'DC-OPT-PF-58' => null,
            ]],
        ]);

        $this->applyMachineSlots('DC-STUDIO-AQUA', [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-STUDIO-RAL' => null,
            ]],
            'steam' => ['label' => 'Lancia vapore', 'items' => [
                'DC-OPT-STUDIO-STEAM-COOL' => null,
            ]],
            'addon' => ['label' => 'Accessori aggiuntivi', 'items' => [
                'DC-OPT-KIT-SCARICO' => null,
                'DC-OPT-PF-58' => null,
            ]],
        ]);

        // Macinini: solo finiture/materiali, nessuna configurazione macchina.
        $this->applyMachineSlots('DC-ONE', [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-GRINDER-RAL-ONE' => null,
                'DC-OPT-GRINDER-OAK-KIT' => null,
                'DC-OPT-GRINDER-WALNUT-KIT' => null,
            ]],
        ]);

        $this->applyMachineSlots('DC-TWO', [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-GRINDER-RAL-TWO' => null,
                'DC-OPT-GRINDER-OAK-KIT' => null,
                'DC-OPT-GRINDER-WALNUT-KIT' => null,
            ]],
        ]);

        $this->applyMachineSlots('DC-MAX', [
            'color' => ['label' => 'Colore/Estetica', 'items' => [
                'DC-OPT-MAX-RAL' => null,
                'DC-OPT-MAX-GLASS' => null,
                'DC-OPT-MAX-WOOD-VENEER' => null,
                'DC-OPT-MAX-DARK-OAK' => null,
                'DC-OPT-MAX-LIGHT-OAK' => null,
                'DC-OPT-MAX-VENICE-WOOD' => null,
                'DC-OPT-MAX-COFFEERAMA' => null,
                'DC-OPT-MAX-STAINLESS' => null,
            ]],
        ]);
    }

    /**
     * @param  array<string, array{label: string, items: array<string, float|null>}>  $slots
     */
    private function applyMachineSlots(string $sku, array $slots): void
    {
        $machine = Product::where('sku', $sku)->first();

        if (! $machine) {
            $this->warn("Prodotto non trovato: {$sku}");

            return;
        }

        // Azzera gli slot esistenti (generici) e ricostruisce da zero:
        // niente quote_products referenzia gli slot, solo i Product, quindi
        // e' sicuro per lo storico preventivi gia' creati.
        $machine->slots()->each(fn (ProductOptionSlot $slot) => $slot->delete());

        $sort = 0;
        foreach ($slots as $slotName => ['label' => $label, 'items' => $items]) {
            if (empty($items)) {
                continue;
            }

            $slot = ProductOptionSlot::create([
                'product_id' => $machine->id,
                'slot_name' => $slotName,
                'label' => $label,
                'min_qty' => 0,
                'max_qty' => null,
                'required' => false,
                'sort_order' => $sort++,
            ]);

            $itemSort = 0;
            foreach ($items as $optionSku => $override) {
                $option = Product::where('sku', $optionSku)->first();

                if (! $option) {
                    $this->warn("Opzione non trovata: {$optionSku} (macchina {$sku})");

                    continue;
                }

                $slot->items()->create([
                    'component_product_id' => $option->id,
                    'price_delta_override' => $override,
                    'sort_order' => $itemSort++,
                ]);
            }
        }
    }
}
