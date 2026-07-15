<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductOptionGroupResource\Pages;
use App\Models\ProductOptionGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductOptionGroupResource extends Resource
{
    protected static ?string $model = ProductOptionGroup::class;

    // Catalogo condiviso (tenant_id nullable): vedi nota identica su CategoryResource.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Catalogo';

    protected static ?string $navigationLabel = 'Gruppi opzione';

    protected static ?string $modelLabel = 'Gruppo opzione';

    protected static ?string $pluralModelLabel = 'Gruppi opzione';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Chiave (es. sistema_latte)')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('label')
                ->label('Etichetta visualizzata (es. Sistema Latte)')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('selection_type')
                ->label('Tipo selezione')
                ->options([
                    'single' => 'Singola (radio - le opzioni si escludono a vicenda)',
                    'multiple' => 'Multipla (checkbox - opzioni cumulabili)',
                ])
                ->default('multiple')
                ->required(),
            Forms\Components\Toggle::make('is_required')
                ->label('Obbligatorio (va sempre scelta un\'opzione del gruppo)'),
            Forms\Components\TextInput::make('sort_order')
                ->label('Ordine')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Etichetta')->searchable(),
                Tables\Columns\TextColumn::make('selection_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'single' ? 'Singola' : 'Multipla'),
                Tables\Columns\IconColumn::make('is_required')->label('Obbligatorio')->boolean(),
                Tables\Columns\TextColumn::make('tenant.name')->label('Tenant')->placeholder('Condiviso'),
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
            'index' => Pages\ManageProductOptionGroups::route('/'),
        ];
    }
}
