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
 * variante base -> unità ausiliarie/opzioni compatibili -> riepilogo. Crea le
 * righe QuoteProduct con la gerarchia parent_quote_product_id già esistente.
 */
class ConfigureMachineAction
{
    public static function make(): Action
    {
        return Action::make('configureMachine')
            ->label('Configura macchina')
            ->icon('heroicon-o-cog-6-tooth')
            ->modalWidth('3xl')
            ->modalHeading('Configura macchina')
            ->steps([
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
                                ->pluck('name', 'id'))
                            ->live()
                            ->required()
                            ->disabled(fn (Forms\Get $get) => blank($get('product_family_id'))),
                    ]),
                Step::make('Unità ausiliarie e opzioni')
                    ->schema(fn (Forms\Get $get) => static::buildExtrasSchema($get('machine_product_id'))),
                Step::make('Riepilogo')
                    ->schema([
                        Forms\Components\Placeholder::make('summary')
                            ->label('')
                            ->content(fn (Forms\Get $get) => $get('machine_product_id')
                                ? 'Conferma per aggiungere la macchina selezionata con le unità/opzioni scelte al preventivo.'
                                : 'Nessuna macchina selezionata.'),
                    ]),
            ])
            ->action(function (array $data, Quote $record) {
                static::createQuoteProducts($record, $data);
            });
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected static function buildExtrasSchema(?string $machineId): array
    {
        if (! $machineId || ! $machine = Product::find($machineId)) {
            return [
                Forms\Components\Placeholder::make('none')
                    ->label('')
                    ->content('Seleziona prima una macchina nel passaggio precedente.'),
            ];
        }

        $compatibilities = $machine->compatibilities()->with(['optionProduct', 'optionGroup'])->get();
        $required = $compatibilities->where('constraint_type', 'required');
        $optionalByGroup = $compatibilities->where('constraint_type', 'compatible')->groupBy('option_group_id');

        $schema = [];

        if ($required->isNotEmpty()) {
            $schema[] = Forms\Components\Placeholder::make('required_info')
                ->label('Incluse automaticamente (obbligatorie per questa variante)')
                ->content($required->pluck('optionProduct.name')->implode(', '));
        }

        foreach ($optionalByGroup as $groupId => $items) {
            $group = $items->first()->optionGroup;
            $fieldName = "group_{$groupId}";
            $options = $items->pluck('optionProduct.name', 'optionProduct.id');

            $schema[] = $group->isSingleChoice()
                ? Forms\Components\Radio::make($fieldName)->label($group->label)->options($options)
                : Forms\Components\CheckboxList::make($fieldName)->label($group->label)->options($options)->columns(2);
        }

        if (empty($schema)) {
            $schema[] = Forms\Components\Placeholder::make('none')
                ->label('')
                ->content('Nessuna unità ausiliaria o opzione compatibile configurata per questa variante.');
        }

        return $schema;
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
