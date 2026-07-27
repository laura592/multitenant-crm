<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceScheduleResource\Pages;
use App\Filament\Resources\MaintenanceScheduleResource\RelationManagers\LavaggiRelationManager;
use App\Models\MaintenanceSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MaintenanceScheduleResource extends Resource
{
    protected static ?string $model = MaintenanceSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Piani di manutenzione';

    protected static ?string $modelLabel = 'Piano di manutenzione';

    protected static ?string $pluralModelLabel = 'Piani di manutenzione';

    // Senza questo, Filament forza il Title Case e capitalizza anche il "di"
    // ("Piani Di Manutenzione").
    protected static bool $hasTitleCaseModelLabel = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cliente e macchina')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('comodato_macchina_id')
                        ->label('Macchina (comodato)')
                        ->relationship('comodatoMacchina', 'nome_macchina')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE),
                ]),
            Forms\Components\Section::make('Pianificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options([
                            MaintenanceSchedule::TYPE_MANUTENZIONE => 'Manutenzione',
                            MaintenanceSchedule::TYPE_LAVAGGIO => 'Lavaggio',
                        ])
                        ->default(MaintenanceSchedule::TYPE_MANUTENZIONE)
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('frequency')
                        ->label('Frequenza')
                        ->options([
                            'mensile' => 'Mensile',
                            'trimestrale' => 'Trimestrale',
                            'semestrale' => 'Semestrale',
                            'annuale' => 'Annuale',
                        ])
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE)
                        ->required(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE),
                    Forms\Components\TextInput::make('frequency_days')
                        ->label('Cadenza (giorni)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Es. 20 o 30. Ogni nuovo lavaggio registrato sposta in automatico la prossima scadenza di questi giorni.')
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO)
                        ->required(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO),
                    Forms\Components\DatePicker::make('next_due_date')
                        ->label('Prossima scadenza')
                        ->required()
                        ->default(now()->addMonth()),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('next_due_date')
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'Lavaggio' : 'Manutenzione')
                    ->color(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('comodatoMacchina.nome_macchina')->label('Macchina')->placeholder('—'),
                Tables\Columns\TextColumn::make('frequenza_label')
                    ->label('Frequenza')
                    ->state(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO
                        ? ($record->frequency_days ? "Ogni {$record->frequency_days} giorni" : '—')
                        : ($record->frequency ? ucfirst($record->frequency) : '—')),
                Tables\Columns\TextColumn::make('next_due_date')
                    ->label('Prossima scadenza')
                    ->date()
                    ->color(fn (MaintenanceSchedule $record) => $record->next_due_date->isPast() ? 'danger' : ($record->next_due_date->diffInDays(now()) <= 30 ? 'warning' : 'success')),
                Tables\Columns\TextColumn::make('ultimo')
                    ->label('Ultimo')
                    ->state(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO
                        ? $record->lastLavaggio?->data?->format('d/m/Y')
                        : $record->lastServiceReport?->number)
                    ->placeholder('Mai eseguito'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        MaintenanceSchedule::TYPE_MANUTENZIONE => 'Manutenzione',
                        MaintenanceSchedule::TYPE_LAVAGGIO => 'Lavaggio',
                    ]),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('due_soon')
                    ->label('In scadenza entro 30 giorni')
                    ->query(fn ($query) => $query->where('next_due_date', '<=', now()->addDays(30))),
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
            LavaggiRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenanceSchedules::route('/'),
            'create' => Pages\CreateMaintenanceSchedule::route('/create'),
            'edit' => Pages\EditMaintenanceSchedule::route('/{record}/edit'),
        ];
    }
}
