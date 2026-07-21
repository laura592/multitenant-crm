<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    // Catalogo condiviso (tenant_id nullable, §4.2): lo scoping automatico
    // nativo di Filament filtra con uguaglianza stretta (tenant_id = tenant
    // corrente) e nasconderebbe tutte le righe condivise (tenant_id NULL).
    // Lo scoping vero lo fa gia' il trait BelongsToTenant sul modello.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Magazzino';

    protected static ?string $navigationLabel = 'Fornitori';

    protected static ?string $modelLabel = 'Fornitore';

    protected static ?string $pluralModelLabel = 'Fornitori';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Fornitore')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Ragione sociale')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('address')
                        ->label('Indirizzo')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('postal_code')
                        ->label('CAP')
                        ->maxLength(10),
                    Forms\Components\TextInput::make('city')
                        ->label('Città')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('province')
                        ->label('Provincia')
                        ->maxLength(2),
                    Forms\Components\TextInput::make('phone')
                        ->label('Telefono')
                        ->tel()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\Textarea::make('notes')
                        ->label('Note')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Ragione sociale')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('city')->label('Città')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->label('Telefono')->placeholder('—'),
                Tables\Columns\TextColumn::make('email')->label('Email')->placeholder('—'),
                Tables\Columns\TextColumn::make('materials_count')->label('Materiali')->counts('materials'),
            ])
            ->defaultSort('name')
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
            'index' => Pages\ManageSuppliers::route('/'),
        ];
    }
}
