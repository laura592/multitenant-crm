<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalogo';

    protected static ?string $navigationLabel = 'Prodotti';

    protected static ?string $modelLabel = 'Prodotto';

    protected static ?string $pluralModelLabel = 'Prodotti';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dati prodotto')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options([
                            Product::TYPE_MACHINE => 'Macchina (variante apparecchio base)',
                            Product::TYPE_AUXILIARY_UNIT => 'Unità ausiliaria',
                            Product::TYPE_OPTION => 'Opzione',
                            Product::TYPE_ACCESSORY => 'Accessorio',
                            Product::TYPE_SERVICE => 'Servizio/licenza',
                        ])
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('product_family_id')
                        ->label('Famiglia macchina')
                        ->relationship('family', 'name')
                        ->visible(fn (Forms\Get $get) => $get('type') === Product::TYPE_MACHINE)
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('category_id')
                        ->label('Categoria')
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('source')
                        ->label('Origine')
                        ->options([
                            Product::SOURCE_FRANKE => 'Franke ufficiale',
                            Product::SOURCE_THIRD_PARTY => 'Terzo',
                        ])
                        ->helperText('Per la garanzia: art. 11.3 del contratto di distribuzione'),
                    Forms\Components\Textarea::make('description')
                        ->label('Descrizione')
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('image')
                        ->label('Immagine')
                        ->image()
                        ->directory('products')
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Visibilità')
                ->visible(fn () => (bool) auth()->user()?->is_super_admin)
                ->schema([
                    Forms\Components\Select::make('tenant_id')
                        ->label('Visibilità')
                        ->helperText('Vuoto = catalogo condiviso con tutti i partner. Un tenant = riservato a quell\'azienda.')
                        ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->dehydrated(fn () => (bool) auth()->user()?->is_super_admin),
                ]),
            Forms\Components\Section::make('Listino prezzi')
                ->schema([
                    Forms\Components\Repeater::make('prices')
                        ->relationship('prices')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            Forms\Components\TextInput::make('price')
                                ->label('Prezzo (€)')
                                ->numeric()
                                ->prefix('€')
                                ->required(),
                            Forms\Components\DatePicker::make('valid_from')->label('Valido da'),
                            Forms\Components\DatePicker::make('valid_to')->label('Valido a'),
                        ])
                        ->defaultItems(1)
                        ->addActionLabel('Aggiungi prezzo')
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label(''),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Product::TYPE_MACHINE => 'Macchina',
                        Product::TYPE_AUXILIARY_UNIT => 'Unità ausiliaria',
                        Product::TYPE_OPTION => 'Opzione',
                        Product::TYPE_ACCESSORY => 'Accessorio',
                        Product::TYPE_SERVICE => 'Servizio',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('family.name')->label('Famiglia'),
                Tables\Columns\TextColumn::make('tenant.name')->label('Visibilità')->placeholder('Condiviso')->badge(),
                Tables\Columns\TextColumn::make('prices.price')
                    ->label('Prezzo corrente')
                    ->state(fn (Product $record) => $record->getCurrentPrice()?->price)
                    ->money('EUR'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        Product::TYPE_MACHINE => 'Macchina',
                        Product::TYPE_AUXILIARY_UNIT => 'Unità ausiliaria',
                        Product::TYPE_OPTION => 'Opzione',
                        Product::TYPE_ACCESSORY => 'Accessorio',
                        Product::TYPE_SERVICE => 'Servizio',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CompatibilitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
