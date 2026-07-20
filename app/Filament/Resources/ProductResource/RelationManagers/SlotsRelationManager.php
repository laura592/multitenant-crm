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

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slot_name')
                ->label('Nome slot (es. cooling_unit, grinder, steam...)')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('label')
                ->label('Etichetta visualizzata (es. Unità di raffreddamento)')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('min_qty')
                ->label('Minimo selezionabile')
                ->numeric()
                ->default(0)
                ->required(),
            Forms\Components\TextInput::make('max_qty')
                ->label('Massimo selezionabile (vuoto = illimitato)')
                ->numeric(),
            Forms\Components\Toggle::make('required')
                ->label('Obbligatorio'),
            Forms\Components\TextInput::make('sort_order')
                ->label('Ordine')
                ->numeric()
                ->default(0),
            Forms\Components\Repeater::make('items')
                ->label('Componenti ammessi')
                ->relationship('items')
                ->schema([
                    Forms\Components\Select::make('component_product_id')
                        ->label('Prodotto')
                        ->options(fn (RelationManager $livewire) => Product::query()
                            ->whereKeyNot($livewire->getOwnerRecord()->getKey())
                            ->whereIn('type', [Product::TYPE_AUXILIARY_UNIT, Product::TYPE_OPTION, Product::TYPE_ACCESSORY])
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    Forms\Components\TextInput::make('price_delta_override')
                        ->label('Sovrapprezzo (vuoto = prezzo di listino)')
                        ->numeric(),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Ordine')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(3)
                ->defaultItems(0)
                ->addActionLabel('Aggiungi componente'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('slot_name')->label('Nome'),
                Tables\Columns\TextColumn::make('label')->label('Etichetta'),
                Tables\Columns\TextColumn::make('min_qty')->label('Min'),
                Tables\Columns\TextColumn::make('max_qty')->label('Max')->placeholder('∞'),
                Tables\Columns\IconColumn::make('required')->label('Obbligatorio')->boolean(),
                Tables\Columns\TextColumn::make('items_count')->label('Componenti')->counts('items'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
