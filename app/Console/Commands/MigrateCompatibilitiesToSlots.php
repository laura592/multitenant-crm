<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCompatibility;
use App\Models\ProductOptionSlot;
use Illuminate\Console\Command;

/**
 * Migrazione una tantum dal vecchio grafo di compatibilita'
 * (product_option_groups + product_compatibilities) al nuovo modello a slot
 * (product_option_slots + product_option_slot_items), verso files/DATABASE-SCHEMA.md.
 *
 * Per ogni prodotto "machine" le compatibilita' vengono raggruppate per
 * option_group_id -> uno slot per gruppo. Uno slot single-item interamente
 * "required" diventa uno slot obbligatorio min=1/max=1 (equivalente
 * all'auto-inclusione del vecchio sistema); se ci sono piu' righe required
 * per lo stesso gruppo, lo slot resta obbligatorio ma con selezione
 * dell'utente (l'utente sceglie comunque quale, min 1).
 *
 * Idempotente: rieseguibile, salta gli slot gia' migrati (unique su
 * product_id+slot_name).
 */
class MigrateCompatibilitiesToSlots extends Command
{
    protected $signature = 'products:migrate-compatibilities-to-slots';

    protected $description = 'Converte product_option_groups/product_compatibilities esistenti in product_option_slots/product_option_slot_items';

    public function handle(): int
    {
        $totalSlots = 0;
        $totalItems = 0;

        $machineIds = ProductCompatibility::query()->distinct()->pluck('base_product_id');

        foreach (Product::whereIn('id', $machineIds)->get() as $machine) {
            $rows = ProductCompatibility::where('base_product_id', $machine->id)
                ->with('optionGroup')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('option_group_id');

            foreach ($rows as $groupRows) {
                $group = $groupRows->first()->optionGroup;

                if (! $group) {
                    continue;
                }

                $requiredRows = $groupRows->where('constraint_type', ProductCompatibility::CONSTRAINT_REQUIRED);
                $isSingleAutoIncluded = $requiredRows->count() === $groupRows->count() && $groupRows->count() === 1;
                $hasRequired = $requiredRows->isNotEmpty();

                $isSingleChoice = $group->selection_type === 'single';

                $slot = ProductOptionSlot::firstOrCreate(
                    ['product_id' => $machine->id, 'slot_name' => $group->name],
                    [
                        'label' => $group->label,
                        'sort_order' => $group->sort_order,
                        'required' => $isSingleAutoIncluded || $hasRequired || $group->is_required,
                        'min_qty' => $isSingleAutoIncluded || $hasRequired || $group->is_required ? 1 : 0,
                        'max_qty' => $isSingleChoice ? 1 : null,
                    ]
                );

                if ($slot->wasRecentlyCreated) {
                    $totalSlots++;
                }

                foreach ($groupRows as $row) {
                    $item = $slot->items()->firstOrCreate(
                        ['component_product_id' => $row->option_product_id],
                        ['sort_order' => $row->sort_order]
                    );

                    if ($item->wasRecentlyCreated) {
                        $totalItems++;
                    }
                }
            }
        }

        $this->info("Slot creati: {$totalSlots}");
        $this->info("Item creati: {$totalItems}");

        return self::SUCCESS;
    }
}
