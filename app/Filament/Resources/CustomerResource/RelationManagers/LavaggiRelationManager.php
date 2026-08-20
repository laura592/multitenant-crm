<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\MaintenanceScheduleResource;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LavaggiRelationManager extends RelationManager
{
    protected static string $relationship = 'lavaggi';

    protected static ?string $title = 'Storico lavaggi';

    protected static ?string $modelLabel = 'Lavaggio';

    // Senza macchinari installati non c'e' nulla da lavare: evita di mostrare
    // una tab vuota e fuorviante sulla scheda cliente.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Customer $ownerRecord */
        return $ownerRecord->installedMachineUnits()->exists();
    }

    // Filament rende di default sola-lettura le RelationManager sulla pagina
    // "Visualizza" (non /edit), nascondendo Delete/Edit nativi anche con i
    // permessi a posto - qui si usa quasi sempre la scheda cliente in
    // visualizzazione, mai la /edit dedicata. Stesso fix di
    // MaintenanceScheduleResource\RelationManagers\LavaggiRelationManager,
    // vedi thread 2026-08-13.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('maintenance_schedule_id')
                ->label('Impianto')
                ->options(fn () => MaintenanceSchedule::query()
                    ->where('customer_id', $this->getOwnerRecord()->id)
                    ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
                    ->get()
                    ->mapWithKeys(fn (MaintenanceSchedule $schedule) => [$schedule->id => MaintenanceScheduleResource::impiantoHero($schedule)]))
                ->searchable()
                ->helperText('A quale impianto (birra, vino, selz...) si riferisce questa visita. Lascia vuoto solo per lavaggi storici non ancora collegati a un piano.'),
            Forms\Components\Select::make('machine_unit_id')
                ->label('Macchina')
                ->relationship('machineUnit', 'serial_number', fn ($query) => $query
                    ->where('current_customer_id', $this->getOwnerRecord()->id))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                ->searchable()
                ->preload()
                ->helperText('Lascia vuoto se la visita ha lavato tutti gli impianti (il caso normale). Seleziona la macchina solo se questa volta ne e\' stato lavato uno solo.'),
            Forms\Components\DatePicker::make('data')->label('Data')->required()->default(now()),
            Forms\Components\TextInput::make('lines_washed')
                ->label('Vie lavate')
                ->numeric()
                ->minValue(0)
                ->helperText('Quante vie di QUESTO impianto sono state lavate. Non rilevante per impianti acqua.'),
            Forms\Components\TextInput::make('descrizione')
                ->label('Note')
                ->helperText('Es. "apertura", "chiusura stagionale". Il conteggio vie va nel campo sopra.')
                ->required()
                ->maxLength(255)
                ->default('Lavaggio Impianto'),
            Forms\Components\Toggle::make('filtro_sostituito')
                ->label('Filtro sostituito in questa visita')
                ->helperText('Solo per impianti acqua: segna quando il filtro viene cambiato, serve a calcolare la prossima scadenza del piano.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('descrizione')
            ->defaultSort('data', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('data')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('lines_washed')->label('Vie lavate')->placeholder('—'),
                Tables\Columns\IconColumn::make('filtro_sostituito')
                    ->label('Filtro sostituito')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('macchina')
                    ->label('Macchina')
                    ->state(fn (Lavaggio $record) => $record->machineLabel())
                    ->wrap(),
                Tables\Columns\TextColumn::make('descrizione')->label('Note')->searchable(),
                Tables\Columns\TextColumn::make('fatturare_a')
                    ->label('Fatturare a')
                    ->state(fn (Lavaggio $record) => $record->billingLabel())
                    ->wrap(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
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
            ->emptyStateHeading('Nessun lavaggio registrato')
            ->emptyStateDescription('Aggiungi il primo lavaggio con "Nuovo".');
    }
}
