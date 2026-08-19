<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SlotsRelationManager extends RelationManager
{
    protected static string $relationship = 'slots';

    protected static ?string $title = 'Slot di configurazione';

    /**
     * Di default Filament rende i relation manager di sola lettura sulla
     * pagina "Vista" (non "Modifica") della risorsa: senza questo override,
     * Nuovo/Modifica/Elimina restano nascosti anche per un super admin,
     * perche' la view page non e' la edit page, non per un problema di permessi.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Impostazioni slot')
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('slot_name')
                                ->label('Nome slot')
                                ->helperText('es. cooling_unit, grinder, steam...')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('label')
                                ->label('Etichetta visualizzata')
                                ->helperText('es. Unità di raffreddamento')
                                ->required()
                                ->maxLength(255),
                        ]),
                    Forms\Components\Radio::make('selection_type')
                        ->label('Tipo di selezione')
                        ->options([
                            'single' => 'Scelta singola',
                            'multiple' => 'Scelta multipla',
                        ])
                        ->helperText('Singola: un solo componente per volta (es. il macinacaffè). Multipla: più componenti insieme (es. gli accessori).')
                        ->default('multiple')
                        ->inline()
                        ->required()
                        ->afterStateHydrated(function (Forms\Components\Radio $component, $record) {
                            if ($record) {
                                $component->state($record->max_qty === 1 ? 'single' : 'multiple');
                            }
                        }),
                    Forms\Components\Toggle::make('required')
                        ->label('Obbligatorio')
                        ->helperText('Il cliente deve scegliere almeno un componente in questo slot.'),
                ]),
            Forms\Components\Section::make('Componenti ammessi')
                ->description('Trascina per riordinare.')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->hiddenLabel()
                        ->relationship('items')
                        ->simple(
                            Forms\Components\Select::make('component_product_id')
                                ->label('Prodotto')
                                ->options(fn (RelationManager $livewire) => Product::query()
                                    ->whereKeyNot($livewire->getOwnerRecord()->getKey())
                                    ->whereIn('type', [Product::TYPE_AUXILIARY_UNIT, Product::TYPE_OPTION, Product::TYPE_ACCESSORY])
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                        )
                        ->reorderable()
                        ->reorderableWithDragAndDrop()
                        ->orderColumn('sort_order')
                        ->defaultItems(0)
                        ->addActionLabel('Aggiungi componente'),
                ]),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mapSelectionTypeToQty($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->mapSelectionTypeToQty($data);
    }

    /**
     * "Tipo di selezione" e "Obbligatorio" sono i concetti che contano per chi
     * configura i prodotti; min_qty/max_qty restano in DB solo perche'
     * ConfigureMachineAction li usa per il wizard (isSingleChoice() e i
     * messaggi di validazione), ma non vanno esposti come numeri grezzi.
     */
    private function mapSelectionTypeToQty(array $data): array
    {
        $data['max_qty'] = ($data['selection_type'] ?? 'multiple') === 'single' ? 1 : null;
        $data['min_qty'] = ($data['required'] ?? false) ? 1 : 0;
        unset($data['selection_type']);

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->modifyQueryUsing(fn ($query) => $query->with('items.component'))
            ->columns([
                Tables\Columns\TextColumn::make('slot_name')->label('Nome'),
                Tables\Columns\TextColumn::make('label')->label('Etichetta'),
                Tables\Columns\IconColumn::make('required')->label('Obbligatorio')->boolean(),
                Tables\Columns\TextColumn::make('items')
                    ->label('Componenti')
                    ->getStateUsing(fn ($record) => $record->items
                        ->map(fn ($item) => $item->component?->name ?? '—')
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->limitList(5)
                    ->expandableLimitedList(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalWidth('4xl'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Modifica')
                    ->modalWidth('4xl'),
                Tables\Actions\DeleteAction::make()->label('Elimina'),
            ]);
    }
}
