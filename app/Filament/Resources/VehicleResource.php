<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Filament\Resources\VehicleResource\RelationManagers\DeadlinesRelationManager;
use App\Models\Deadline;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?string $navigationLabel = 'Automezzi';

    protected static ?string $modelLabel = 'Automezzo';

    protected static ?string $pluralModelLabel = 'Automezzi';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Identificazione')
                ->columns(4)
                ->schema([
                    TextEntry::make('plate')->label('Targa'),
                    TextEntry::make('brand')->label('Marca')->placeholder('—'),
                    TextEntry::make('model')->label('Modello')->placeholder('—'),
                    TextEntry::make('year')->label('Anno')->placeholder('—'),
                ]),
            InfolistSection::make('Scadenze attive')
                ->columns(3)
                ->schema([
                    TextEntry::make('insurance_due_date')
                        ->label('Assicurazione')
                        ->state(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_ASSICURAZIONE)?->due_date)
                        ->date()
                        ->placeholder('—')
                        ->badge()
                        ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_ASSICURAZIONE))),
                    TextEntry::make('revision_due_date')
                        ->label('Revisione')
                        ->state(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_REVISIONE)?->due_date)
                        ->date()
                        ->placeholder('—')
                        ->badge()
                        ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_REVISIONE))),
                    TextEntry::make('bollo_due_date')
                        ->label('Bollo')
                        ->state(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_BOLLO)?->due_date)
                        ->date()
                        ->placeholder('—')
                        ->badge()
                        ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_BOLLO))),
                ]),
            InfolistSection::make('Assegnazione')
                ->columns(1)
                ->schema([
                    TextEntry::make('assignedUser.name')->label('Assegnato a')->placeholder('Nessuno (mezzo aziendale)'),
                    TextEntry::make('notes')->label('Note')->placeholder('—'),
                ]),
        ]);
    }

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
            ->modifyQueryUsing(fn ($query) => $query->with('deadlines'))
            ->columns([
                Tables\Columns\TextColumn::make('plate')->label('Targa')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('brand')->label('Marca')->sortable(),
                Tables\Columns\TextColumn::make('model')->label('Modello')->sortable(),
                Tables\Columns\TextColumn::make('year')->label('Anno')->sortable(),
                Tables\Columns\TextColumn::make('insurance_due_date')
                    ->label('Assicurazione')
                    ->getStateUsing(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_ASSICURAZIONE)?->due_date)
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_ASSICURAZIONE))),
                Tables\Columns\TextColumn::make('revision_due_date')
                    ->label('Revisione')
                    ->getStateUsing(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_REVISIONE)?->due_date)
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_REVISIONE))),
                Tables\Columns\TextColumn::make('assignedUser.name')->label('Assegnato a')->sortable(),
            ])
            ->defaultSort('plate')
            ->filters([
                Tables\Filters\SelectFilter::make('assigned_user_id')
                    ->label('Assegnato a')
                    ->relationship('assignedUser', 'name'),
                Tables\Filters\Filter::make('deadlines_due_soon')
                    ->label('Assicurazione/bollo/revisione in scadenza (30 gg)')
                    ->query(fn ($query) => $query->whereHas('deadlines', fn ($query) => $query
                        ->where('status', Deadline::STATUS_ATTIVA)
                        ->whereIn('type', [Deadline::TYPE_ASSICURAZIONE, Deadline::TYPE_BOLLO, Deadline::TYPE_REVISIONE])
                        ->where('due_date', '<=', now()->addDays(30)))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun automezzo ancora')
            ->emptyStateDescription('Aggiungi il primo automezzo con "Nuovo".')
            ->emptyStateIcon('heroicon-o-truck');
    }

    private static function deadlineColor(?Deadline $deadline): ?string
    {
        return match (true) {
            $deadline === null => null,
            $deadline->due_date->isPast() => 'danger',
            $deadline->isUrgent() => 'warning',
            default => 'success',
        };
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
            'view' => Pages\ViewVehicle::route('/{record}'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
