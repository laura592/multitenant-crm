<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CompatibilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'compatibilities';

    protected static ?string $title = 'Unità ausiliarie / opzioni compatibili';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('option_product_id')
                ->label('Prodotto (opzione/unità ausiliaria/accessorio)')
                ->options(fn () => Product::query()
                    ->whereKeyNot($this->getOwnerRecord()->getKey())
                    ->whereIn('type', [Product::TYPE_AUXILIARY_UNIT, Product::TYPE_OPTION, Product::TYPE_ACCESSORY])
                    ->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\Select::make('option_group_id')
                ->label('Gruppo opzione')
                ->relationship('optionGroup', 'label')
                ->searchable()
                ->preload()
                ->required(),
            Forms\Components\Select::make('constraint_type')
                ->label('Vincolo')
                ->options([
                    'compatible' => 'Compatibile (a scelta)',
                    'required' => 'Obbligatorio con questa variante',
                ])
                ->default('compatible')
                ->required(),
            Forms\Components\TextInput::make('sort_order')
                ->label('Ordine')
                ->numeric()
                ->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('option_product_id')
            ->columns([
                Tables\Columns\TextColumn::make('optionProduct.name')->label('Prodotto'),
                Tables\Columns\TextColumn::make('optionGroup.label')->label('Gruppo'),
                Tables\Columns\TextColumn::make('constraint_type')
                    ->label('Vincolo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'required' ? 'Obbligatorio' : 'Compatibile'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
