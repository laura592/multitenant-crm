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

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Automezzi';

    protected static ?string $modelLabel = 'Automezzo';

    protected static ?string $pluralModelLabel = 'Automezzi';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    TextEntry::make('plate')->label('Targa')->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('brand')->label('Marca')->placeholder('—')->columnSpan(['default' => 1, 'lg' => 3]),
                    TextEntry::make('model')->label('Modello')->placeholder('—')->columnSpan(['default' => 1, 'lg' => 3]),
                    TextEntry::make('year')->label('Anno')->placeholder('—')->columnSpan(1),
                    TextEntry::make('assignedUser.name')
                        ->label('Assegnato a')
                        ->placeholder('Mezzo aziendale')
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                ]),
            InfolistSection::make('Scadenze attive')
                ->columns(3)
                ->schema([
                    TextEntry::make('insurance_due_date')
                        ->label('Assicurazione')
                        ->icon('heroicon-o-shield-check')
                        ->state(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_ASSICURAZIONE)?->due_date)
                        ->date()
                        ->placeholder('Nessuna scadenza attiva')
                        ->badge()
                        ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_ASSICURAZIONE))),
                    TextEntry::make('revision_due_date')
                        ->label('Revisione')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->state(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_REVISIONE)?->due_date)
                        ->date()
                        ->placeholder('Nessuna scadenza attiva')
                        ->badge()
                        ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_REVISIONE))),
                    TextEntry::make('bollo_due_date')
                        ->label('Bollo')
                        ->icon('heroicon-o-document-text')
                        ->state(fn (Vehicle $record) => $record->activeDeadline(Deadline::TYPE_BOLLO)?->due_date)
                        ->date()
                        ->placeholder('Nessuna scadenza attiva')
                        ->badge()
                        ->color(fn (Vehicle $record) => self::deadlineColor($record->activeDeadline(Deadline::TYPE_BOLLO))),
                ]),
            InfolistSection::make('Note')
                ->schema([
                    TextEntry::make('notes')->label('')->placeholder('—'),
                ])
                ->visible(fn (Vehicle $record) => filled($record->notes)),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('plate')->label('Targa')->required()->maxLength(255)
                        ->extraAttributes(['data-tour' => 'vehicles-field-plate']),
                    Forms\Components\TextInput::make('brand')->label('Marca')->maxLength(255),
                    Forms\Components\TextInput::make('model')->label('Modello')->maxLength(255),
                    Forms\Components\TextInput::make('year')->label('Anno')->numeric(),
                    Forms\Components\DatePicker::make('immatricolato_il')
                        ->label('Immatricolato il')
                        ->helperText('Da qui si conta la prima revisione quando non la si ha sotto mano.'),
                ]),
            Forms\Components\Section::make('Uscita dalla flotta')
                ->description('Si compila solo quando il mezzo non e\' piu\' dell\'azienda.')
                ->collapsed(fn (Forms\Get $get) => blank($get('dismesso_il')))
                ->schema([
                    Forms\Components\DatePicker::make('dismesso_il')
                        ->label('Dismesso il')
                        ->helperText('Venduto, rottamato o riconsegnato. Il mezzo resta a sistema con tutta la sua storia, ma smette di comparire fra quelli da mettere in regola.'),
                ]),
            // Il bollo del Vito e' nel canone di leasing: senza questo
            // elenco l'unico modo di far smettere l'avviso sarebbe inventare
            // una scadenza finta. Il motivo e' obbligatorio perche' fra un
            // anno la domanda non sara' "e' esente?" ma "perche'?".
            Forms\Components\Section::make('Documenti che questo mezzo non ha')
                ->description('Non dimenticanze: cose che per questo mezzo non esistono, tipo il bollo compreso nel leasing.')
                ->collapsed(fn (Forms\Get $get) => blank($get('esenzioniScadenze')))
                ->schema([
                    Forms\Components\Repeater::make('esenzioniScadenze')
                        ->label('')
                        ->relationship()
                        ->addActionLabel('Aggiungi un documento che non si applica')
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('type')
                                ->label('Documento')
                                ->options(fn () => collect(Vehicle::TIPI_OBBLIGATORI)
                                    ->mapWithKeys(fn (string $tipo) => [$tipo => Deadline::typeLabels()[$tipo] ?? $tipo])
                                    ->all())
                                ->required()
                                ->native(false),
                            Forms\Components\TextInput::make('motivo')
                                ->label('Perche\' non si applica')
                                ->placeholder('Compreso nel canone di leasing Mercedes-Benz Financial')
                                ->required()
                                ->maxLength(255),
                        ]),
                ]),
            Forms\Components\Section::make('Assegnazione')
                ->schema([
                    Forms\Components\Select::make('assigned_user_id')
                        ->label('Assegnato a')
                        ->relationship('assignedUser', 'name')
                        ->searchable()
                        ->preload()
                        ->extraAttributes(['data-tour' => 'vehicles-field-assigned']),
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
                Tables\Columns\TextColumn::make('brand')->visibleFrom('md')->label('Marca')->sortable(),
                Tables\Columns\TextColumn::make('model')->wrap()->label('Modello')->sortable(),
                Tables\Columns\TextColumn::make('year')->visibleFrom('md')->label('Anno')->sortable(),
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
                Tables\Columns\TextColumn::make('assignedUser.name')->visibleFrom('md')->label('Assegnato a')->sortable(),
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
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
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
