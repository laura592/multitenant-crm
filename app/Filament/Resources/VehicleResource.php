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

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Automezzi';

    protected static ?string $modelLabel = 'Automezzo';

    protected static ?string $pluralModelLabel = 'Automezzi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('plate')->label('Targa')->required()->maxLength(255),
                    Forms\Components\TextInput::make('brand')->label('Marca')->maxLength(255),
                    Forms\Components\TextInput::make('model')->label('Modello')->maxLength(255),
                    Forms\Components\TextInput::make('year')->label('Anno')->numeric(),
                ]),
            Forms\Components\Section::make('Scadenze')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('insurance_due_date')->label('Scadenza assicurazione'),
                    Forms\Components\DatePicker::make('revision_due_date')->label('Scadenza revisione'),
                ]),
            Forms\Components\Section::make('Assegnazione')
                ->schema([
                    Forms\Components\Select::make('assigned_user_id')
                        ->label('Assegnato a')
                        ->relationship('assignedUser', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
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
                Tables\Columns\TextColumn::make('insurance_due_date')->label('Assicurazione')->date()
                    ->color(fn (?\Carbon\Carbon $state) => match (true) {
                        $state === null => null,
                        $state->isPast() => 'danger',
                        $state->diffInDays(now(), false) >= -30 => 'warning',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('revision_due_date')->label('Revisione')->date()
                    ->color(fn (?\Carbon\Carbon $state) => match (true) {
                        $state === null => null,
                        $state->isPast() => 'danger',
                        $state->diffInDays(now(), false) >= -30 => 'warning',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('assignedUser.name')->label('Assegnato a'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('assigned_user_id')
                    ->label('Assegnato a')
                    ->relationship('assignedUser', 'name'),
                Tables\Filters\Filter::make('deadlines_due_soon')
                    ->label('Assicurazione/revisione in scadenza (30 gg)')
                    ->query(fn ($query) => $query->where(fn ($query) => $query
                        ->where('insurance_due_date', '<=', now()->addDays(30))
                        ->orWhere('revision_due_date', '<=', now()->addDays(30)))),
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
