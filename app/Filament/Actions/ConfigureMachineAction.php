<?php

namespace App\Filament\Actions;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductOptionSlot;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Wizard di configurazione macchina (files/PRD.md §3.1, files/DATABASE-SCHEMA.md):
 * famiglia -> variante base -> uno step per ciascuno slot noto (unità di
 * raffreddamento, macinacaffè, dosatori, vapore, ...) -> riepilogo. Ogni
 * step e' nascosto automaticamente se la macchina scelta non ha uno slot
 * di quel nome - "Altre opzioni" fa da rete di sicurezza per qualunque slot
 * non tra quelli noti, cosi' nessuno slot sparisce mai anche se in futuro
 * comparira' un nome nuovo. La lista di step resta STATICA tra un render e
 * l'altro (solo contenuto/visibilita' sono reattivi): un closure sull'intero
 * array di step spezza l'idratazione Livewire dei componenti.
 *
 * Uno slot obbligatorio con un solo componente ammesso e' auto-incluso senza
 * scelta dell'utente (equivalente al vecchio "compatibilita' required");
 * uno slot obbligatorio con piu' componenti ammessi richiede comunque una
 * scelta dell'utente (min 1), a differenza del vecchio sistema che non
 * supportava "obbligatorio ma a scelta".
 *
 * Prezzo sempre visibile accanto a ogni opzione (nome variante inclusa),
 * per poter decidere consapevolmente durante la selezione.
 */
class ConfigureMachineAction
{
    /** slot_name -> etichetta step */
    protected const KNOWN_SLOTS = [
        'cooling_unit' => 'Unità di raffreddamento',
        'grinder' => 'Macinacaffè',
        'powder' => 'Dosatori polvere',
        'steam' => 'Lancia vapore',
        'addon' => 'Accessori aggiuntivi',
        'color' => 'Colore/Estetica',
        'power' => 'Alimentazione',
        'license' => 'Licenze e servizi',
    ];

    public static function make(): Action
    {
        $steps = [
            Step::make('Macchina')
                ->schema([
                    Forms\Components\Select::make('product_family_id')
                        ->label('Famiglia')
                        ->options(fn () => ProductFamily::query()->orderBy('name')->pluck('name', 'id'))
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('machine_product_id')
                        ->label('Variante apparecchio base')
                        ->options(fn (Forms\Get $get) => Product::query()
                            ->where('product_family_id', $get('product_family_id'))
                            ->where('type', Product::TYPE_MACHINE)
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => static::formatOptionLabel($p)]))
                        ->live()
                        ->required()
                        ->disabled(fn (Forms\Get $get) => blank($get('product_family_id'))),
                ]),
            Step::make('Incluse automaticamente')
                ->visible(fn (Forms\Get $get) => static::autoIncludedSlots($get)->isNotEmpty())
                ->schema([
                    Forms\Components\Placeholder::make('required_info')
                        ->label('Obbligatorie per questa variante')
                        ->content(fn (Forms\Get $get) => static::autoIncludedSlots($get)
                            ->map(fn (ProductOptionSlot $slot) => static::formatOptionLabel($slot->items->first()->component))
                            ->implode(', ')),
                ]),
        ];

        foreach (self::KNOWN_SLOTS as $slotName => $stepLabel) {
            $steps[] = Step::make($stepLabel)
                ->visible(fn (Forms\Get $get) => static::choosableSlots($get, $slotName)->isNotEmpty())
                ->schema(fn (Forms\Get $get) => static::slotStepSchema($get, $slotName));
        }

        // Rete di sicurezza: qualunque slot non elencato sopra (nome
        // sconosciuto/futuro) finisce comunque qui, mai perso silenziosamente.
        $steps[] = Step::make('Altre opzioni')
            ->visible(fn (Forms\Get $get) => static::choosableSlots($get, null)->isNotEmpty())
            ->schema(fn (Forms\Get $get) => static::slotStepSchema($get, null));

        $steps[] = Step::make('Riepilogo')
            ->schema([
                Forms\Components\Placeholder::make('summary')
                    ->label('')
                    ->content(fn (Forms\Get $get) => $get('machine_product_id')
                        ? 'Conferma per aggiungere la macchina selezionata con le unità/opzioni scelte al preventivo.'
                        : 'Nessuna macchina selezionata.'),
            ]);

        return Action::make('configureMachine')
            ->label('Configura macchina')
            ->icon('heroicon-o-cog-6-tooth')
            ->modalWidth('3xl')
            ->modalHeading('Configura macchina')
            ->steps($steps)
            ->action(function (array $data, Quote $record, $livewire) {
                static::createQuoteProducts($record, $data);

                // Le righe sono create scrivendo sul modello, fuori dal ciclo
                // form/tabella del RelationManager "Righe preventivo": senza
                // questo evento il tab resta con lo stato di quando e' stato
                // aperto finche' non si interagisce di nuovo con la tabella
                // (bug reale segnalato: "se ricarico la pagina" le righe compaiono).
                $livewire->dispatch('quoteProductsUpdated');
            });
    }

    protected static function currentMachine(Forms\Get $get): ?Product
    {
        $machineId = $get('machine_product_id');

        return $machineId ? Product::find($machineId) : null;
    }

    protected static function allSlots(Forms\Get $get): Collection
    {
        $machine = static::currentMachine($get);

        if (! $machine) {
            return collect();
        }

        return $machine->slots()->with('items.component')->get();
    }

    /**
     * Slot obbligatori con un solo componente ammesso: nessuna scelta per
     * l'utente, aggiunti automaticamente (equivalente al vecchio
     * "constraint_type = required").
     */
    protected static function autoIncludedSlots(Forms\Get $get): Collection
    {
        return static::allSlots($get)->filter(
            fn (ProductOptionSlot $slot) => $slot->required && $slot->items->count() === 1
        );
    }

    /**
     * Slot che richiedono una scelta dell'utente (non auto-inclusi) per un
     * dato slot_name. $slotName = null -> rete di sicurezza: tutti gli slot
     * con nome NON tra quelli noti (KNOWN_SLOTS).
     */
    protected static function choosableSlots(Forms\Get $get, ?string $slotName): Collection
    {
        $known = array_keys(self::KNOWN_SLOTS);

        return static::allSlots($get)
            ->reject(fn (ProductOptionSlot $slot) => $slot->required && $slot->items->count() === 1)
            ->filter(function (ProductOptionSlot $slot) use ($slotName, $known) {
                return $slotName === null ? ! in_array($slot->slot_name, $known) : $slot->slot_name === $slotName;
            });
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected static function slotStepSchema(Forms\Get $get, ?string $slotName): array
    {
        $slots = static::choosableSlots($get, $slotName);

        if ($slots->isEmpty()) {
            return [];
        }

        // Un gruppo "sicurezza" (null) puo' in realta' raggruppare piu'
        // slot diversi: un field per ciascuno slot realmente presente.
        $schema = [];

        foreach ($slots as $slot) {
            $fieldName = "slot_{$slot->id}";
            $options = $slot->items->mapWithKeys(
                fn ($item) => [$item->component_product_id => static::formatOptionLabel($item->component)]
            );
            $label = $slotName === null ? $slot->label : '';

            if ($slot->isSingleChoice()) {
                $schema[] = Forms\Components\Radio::make($fieldName)->label($label)->options($options)->required($slot->required);

                continue;
            }

            $checkboxList = Forms\Components\CheckboxList::make($fieldName)->label($label)->options($options)->columns(2);

            if ($slot->required && $slot->min_qty > 0) {
                $checkboxList = $checkboxList->required()->minItems($slot->min_qty);
            }

            if ($slot->max_qty !== null) {
                $checkboxList = $checkboxList->maxItems($slot->max_qty);
            }

            $schema[] = $checkboxList;
        }

        return $schema;
    }

    /**
     * "Nome prodotto — 1.234,56 €", cosi' il prezzo e' visibile mentre si
     * sceglie, non solo nel riepilogo finale.
     */
    protected static function formatOptionLabel(Product $product): string
    {
        $price = $product->getCurrentPrice()?->price;

        if ($price === null) {
            return $product->name;
        }

        return $product->name.' — '.number_format((float) $price, 2, ',', '.').' €';
    }

    protected static function createQuoteProducts(Quote $quote, array $data): void
    {
        $machine = Product::find($data['machine_product_id'] ?? null);

        if (! $machine) {
            Notification::make()->title('Nessuna macchina selezionata')->danger()->send();

            return;
        }

        $selectedIds = $machine->slots()->with('items')->get()
            ->filter(fn (ProductOptionSlot $slot) => $slot->required && $slot->items->count() === 1)
            ->flatMap(fn (ProductOptionSlot $slot) => $slot->items->pluck('component_product_id'));

        foreach ($data as $key => $value) {
            if (! str_starts_with($key, 'slot_')) {
                continue;
            }

            $selectedIds = $selectedIds->merge(is_array($value) ? $value : [$value]);
        }

        $selectedIds = $selectedIds->filter()->unique()->values();

        if ($violation = static::findConstraintViolation($selectedIds)) {
            Notification::make()->title($violation)->danger()->send();

            return;
        }

        $baseLine = $quote->quoteProducts()->create([
            'product_id' => $machine->id,
            'quantity' => 1,
            'price' => $machine->getCurrentPrice()?->price ?? 0,
            'discount' => 0,
            'tax' => 22,
        ]);

        Product::whereIn('id', $selectedIds)->get()->each(function (Product $product) use ($quote, $baseLine) {
            $quote->quoteProducts()->create([
                'product_id' => $product->id,
                'parent_quote_product_id' => $baseLine->id,
                'quantity' => 1,
                'price' => $product->getCurrentPrice()?->price ?? 0,
                'discount' => 0,
                'tax' => 22,
            ]);
        });

        $quote->updateTotal();

        Notification::make()->title('Macchina configurata e aggiunta al preventivo')->success()->send();
    }

    /**
     * Verifica i vincoli requires/excludes (files/DATABASE-SCHEMA.md).
     * Ritorna un messaggio d'errore se un vincolo è violato, altrimenti null.
     */
    protected static function findConstraintViolation(Collection $selectedIds): ?string
    {
        $products = Product::whereIn('id', $selectedIds)->get()->keyBy('id');

        foreach ($products as $product) {
            foreach ($product->requiredProducts as $requirement) {
                if (! $selectedIds->contains($requirement->id)) {
                    return "«{$product->name}» richiede anche «{$requirement->name}», non selezionato.";
                }
            }

            foreach ($product->excludedProducts as $exclusion) {
                if ($selectedIds->contains($exclusion->id)) {
                    return "«{$product->name}» non è compatibile con «{$exclusion->name}».";
                }
            }
        }

        return null;
    }
}
