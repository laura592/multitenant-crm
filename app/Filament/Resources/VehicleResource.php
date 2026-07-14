<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Filament\Resources\VehicleResource\RelationManagers\DeadlinesRelationManager;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Gestione';

    protected static ?string $navigationLabel = 'Automezzi';

    protected static ?string $modelLabel = 'Automezzo';

    protected static ?string $pluralModelLabel = 'Automezzi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('plate')->label('Targa')->required()->maxLength(255),
            Forms\Components\TextInput::make('brand')->label('Marca')->maxLength(255),
            Forms\Components\TextInput::make('model')->label('Modello')->maxLength(255),
            Forms\Components\TextInput::make('year')->label('Anno')->numeric(),
            Forms\Components\Select::make('assigned_user_id')
                ->label('Assegnato a')
                ->relationship('assignedUser', 'name')
                ->searchable()
                ->preload(),
            Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plate')->label('Targa')->searchable(),
                Tables\Columns\TextColumn::make('brand')->label('Marca'),
                Tables\Columns\TextColumn::make('model')->label('Modello'),
                Tables\Columns\TextColumn::make('year')->label('Anno'),
                Tables\Columns\TextColumn::make('assignedUser.name')->label('Assegnato a'),
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
            DeadlinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
