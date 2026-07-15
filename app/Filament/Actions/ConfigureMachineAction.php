<?php

namespace App\Filament\Actions;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Wizard di configurazione macchina (docs/architecture.md §11.2): famiglia ->
 * variante base -> uno step per ciascuna categoria nota (unità di
 * raffreddamento, macinacaffè, dosatori, vapore, ...) -> riepilogo. Ogni
 * step e' nascosto automaticamente se non ci sono opzioni compatibili in
 * quella categoria per la macchina scelta - "Altre opzioni" fa da rete di
 * sicurezza per qualunque gruppo non tra quelli noti, cosi' nessuna opzione
 * sparisce mai anche se in futuro comparira' un gruppo nuovo. La lista di
 * step resta STATICA tra un render e l'altro (solo contenuto/visibilita'
 * sono reattivi): un closure sull'intero array di step spezza l'idratazione
 * Livewire dei componenti.
 *
 * Prezzo sempre visibile accanto a ogni opzione (nome variante inclusa),
 * per poter decidere consapevolmente durante la selezione.
 */
class ConfigureMachineAction
{
    /** name gruppo -> etichetta step */
    protected const KNOWN_GROUPS = [
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
                ->visible(fn (Forms\Get $get) => static::requiredCompatibilities($get)->isNotEmpty())
                ->schema([
                    Forms\Components\Placeholder::make('required_info')
                        ->label('Obbligatorie per questa variante')
                        ->content(fn (Forms\Get $get) => static::requiredCompatibilities($get)
                            ->map(fn ($c) => static::formatOptionLabel($c->optionProduct))
                            ->implode(', ')),
                ]),
        ];

        foreach (self::KNOWN_GROUPS as $groupName => $stepLabel) {
            $steps[] = Step::make($stepLabel)
                ->visible(fn (Forms\Get $get) => static::groupCompatibilities($get, $groupName)->isNotEmpty())
                ->schema(fn (Forms\Get $get) => static::groupStepSchema($get, $groupName));
        }

        // Rete di sicurezza: qualsiasi gruppo non elencato sopra (nome
        // sconosciuto/futuro) finisce comunque qui, mai perso silenziosamente.
        $steps[] = Step::make('Altre opzioni')
            ->visible(fn (Forms\Get $get) => static::groupCompatibilities($get, null)->isNotEmpty())
            ->schema(fn (Forms\Get $get) => static::groupStepSchema($get, null));

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
            ->action(function (array $data, Quote $record) {
                static::createQuoteProducts($record, $data);
            });
    }

    protected static function currentMachine(Forms\Get $get): ?Product
    {
        $machineId = $get('machine_product_id');

        return $machineId ? Product::find($machineId) : null;
    }

    protected static function requiredCompatibilities(Forms\Get $get): Collection
    {
        $machine = static::currentMachine($get);

        if (! $machine) {
            return collect();
        }

        return $machine->compatibilities()
            ->with('optionProduct')
            ->where('constraint_type', 'required')
            ->get();
    }

    /**
     * Compatibilità "compatible" (non obbligatorie) per un dato nome gruppo.
     * $groupName = null -> rete di sicurezza: tutti i gruppi NON elencati
     * in KNOWN_GROUPS.
     */
    protected static function groupCompatibilities(Forms\Get $get, ?string $groupName): Collection
    {
        $machine = static::currentMachine($get);

        if (! $machine) {
            return collect();
        }

        $known = array_keys(self::KNOWN_GROUPS);

        return $machine->compatibilities()
            ->with(['optionProduct', 'optionGroup'])
            ->where('constraint_type', 'compatible')
            ->get()
            ->filter(function ($c) use ($groupName, $known) {
                $name = $c->optionGroup->name;

                return $groupName === null ? ! in_array($name, $known) : $name === $groupName;
            });
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected static function groupStepSchema(Forms\Get $get, ?string $groupName): array
    {
        $compatibilities = static::groupCompatibilities($get, $groupName);

        if ($compatibilities->isEmpty()) {
            return [];
        }

        // Un gruppo "sicurezza" (null) può in realtà raggruppare piu' gruppi
        // DB diversi: un field per ciascun option_group_id realmente presente.
        $schema = [];

        foreach ($compatibilities->groupBy('option_group_id') as $groupId => $items) {
            $group = $items->first()->optionGroup;
            $fieldName = "group_{$groupId}";
            $options = $items->pluck('optionProduct')->mapWithKeys(
                fn (Product $p) => [$p->id => static::formatOptionLabel($p)]
            );

            $schema[] = $group->isSingleChoice()
                ? Forms\Components\Radio::make($fieldName)->label($groupName === null ? $group->label : '')->options($options)
                : Forms\Components\CheckboxList::make($fieldName)->label($groupName === null ? $group->label : '')->options($options)->columns(2);
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

        $selectedIds = $machine->compatibilities()
            ->where('constraint_type', 'required')
            ->pluck('option_product_id');

        foreach ($data as $key => $value) {
            if (! str_starts_with($key, 'group_')) {
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
     * Verifica i vincoli requires/excludes (docs/architecture.md §11.2).
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
