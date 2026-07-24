<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    // Catalogo condiviso (tenant_id nullable, §4.2): lo scoping automatico
    // nativo di Filament filtra con uguaglianza stretta (tenant_id = tenant
    // corrente) e nasconderebbe tutte le righe condivise (tenant_id NULL).
    // Lo scoping vero lo fa gia' il trait BelongsToTenant sul modello.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Catalogo';

    protected static ?string $navigationLabel = 'Categorie';

    protected static ?string $modelLabel = 'Categoria';

    protected static ?string $pluralModelLabel = 'Categorie';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('parent_id')
                ->label('Categoria padre')
                ->options(fn (?Category $record) => Category::query()
                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('parent.name')->label('Categoria padre')->placeholder('—'),
                Tables\Columns\TextColumn::make('tenant.name')->label('Tenant')->placeholder('Condivisa')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('products_count')->label('Prodotti')->counts('products'),
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
            'index' => Pages\ManageCategories::route('/'),
        ];
    }
}
