<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductFamilyResource\Pages;
use App\Models\ProductFamily;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductFamilyResource extends Resource
{
    protected static ?string $model = ProductFamily::class;

    // Catalogo condiviso (tenant_id nullable): vedi nota identica su CategoryResource.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Catalogo';

    protected static ?string $navigationLabel = 'Famiglie macchina';

    protected static ?string $modelLabel = 'Famiglia macchina';

    protected static ?string $pluralModelLabel = 'Famiglie macchina';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dati famiglia')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome (es. A300)')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Ordine')
                        ->numeric()
                        ->default(0),
                    Forms\Components\Textarea::make('description')
                        ->label('Descrizione')
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('image')
                        ->label('Immagine')
                        ->image()
                        ->directory('product-families')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label(''),
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('tenant.name')->label('Tenant')->placeholder('Condivisa'),
                Tables\Columns\TextColumn::make('products_count')->label('Varianti')->counts('products'),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageProductFamilies::route('/'),
        ];
    }
}
