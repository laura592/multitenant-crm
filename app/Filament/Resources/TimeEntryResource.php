<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToOwnUserUnlessResponsabile;
use App\Filament\Resources\TimeEntryResource\Pages;
use App\Models\TimeEntry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TimeEntryResource extends Resource
{
    use ScopesToOwnUserUnlessResponsabile;

    protected static ?string $model = TimeEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Gestione';

    protected static ?string $navigationLabel = 'Presenze';

    protected static ?string $modelLabel = 'Timbratura';

    protected static ?string $pluralModelLabel = 'Presenze';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')
                ->label('Dipendente')
                ->relationship('user', 'name')
                ->default(fn () => auth()->id())
                ->disabled(fn () => ! static::isResponsabile(auth()->user()))
                ->dehydrated()
                ->required(),
            Forms\Components\DateTimePicker::make('clock_in')->label('Entrata')->required(),
            Forms\Components\DateTimePicker::make('clock_out')->label('Uscita'),
            Forms\Components\Select::make('source')
                ->label('Origine')
                ->options(['app' => 'App (tempo reale)', 'manuale' => 'Inserimento manuale'])
                ->default('manuale')
                ->required(),
            Forms\Components\Select::make('status')
                ->label('Stato')
                ->options(['aperta' => 'Aperta', 'chiusa' => 'Chiusa', 'corretta' => 'Corretta'])
                ->default('chiusa')
                ->required(),
            Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('clock_in', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Dipendente')->searchable(),
                Tables\Columns\TextColumn::make('clock_in')->label('Entrata')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('clock_out')->label('Uscita')->dateTime('d/m/Y H:i')->placeholder('In corso'),
                Tables\Columns\TextColumn::make('worked_hours')->label('Ore')->state(fn (TimeEntry $record) => $record->worked_hours),
                Tables\Columns\TextColumn::make('source')->label('Origine')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Stato')->badge(),
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
            'index' => Pages\ListTimeEntries::route('/'),
            'create' => Pages\CreateTimeEntry::route('/create'),
            'edit' => Pages\EditTimeEntry::route('/{record}/edit'),
        ];
    }
}
