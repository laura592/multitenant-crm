<?php

namespace App\Filament\Resources\MaintenanceScheduleResource\RelationManagers;

use App\Filament\Resources\ServiceReportResource;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LavaggiRelationManager extends RelationManager
{
    protected static string $relationship = 'lavaggi';

    protected static ?string $title = 'Lavaggi eseguiti';

    protected static ?string $modelLabel = 'Lavaggio';

    // Ha senso solo per i piani di tipo lavaggio: per le manutenzioni gli
    // interventi si registrano tramite ServiceReport, non qui.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === MaintenanceSchedule::TYPE_LAVAGGIO;
    }

    public static function serviceReportCreateUrl(Lavaggio $record): string
    {
        $query = array_filter([
            'customer_id' => $record->customer_id,
            'machine_unit_id' => $record->machine_unit_id,
            'intervention_date' => $record->data?->toDateString(),
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'problem_description' => 'Lavaggio impianto',
            'work_performed' => $record->descrizione,
            'notes' => $record->note,
        ], fn ($value) => filled($value));

        return ServiceReportResource::getUrl('create', tenant: $record->tenant).'?'.http_build_query($query);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('machine_unit_id')
                ->label('Macchina')
                ->relationship('machineUnit', 'serial_number', fn ($query) => $query
                    ->where('current_customer_id', $this->getOwnerRecord()->customer_id))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                ->searchable()
                ->preload()
                ->helperText('Lascia vuoto se la visita ha lavato tutti gli impianti (il caso normale). Seleziona la macchina solo se questa volta ne e\' stato lavato uno solo.'),
            Forms\Components\DatePicker::make('data')->label('Data')->required()->default(now()),
            Forms\Components\TextInput::make('descrizione')
                ->label('Descrizione')
                ->helperText('Es. "5 vie + apertura", "chiusura stagionale".')
                ->required()
                ->maxLength(255),
            Forms\Components\Toggle::make('filtro_sostituito')
                ->label('Filtro sostituito in questa visita')
                ->helperText('Impianti acqua: segna quando il filtro viene cambiato, serve a calcolare la prossima scadenza del piano.')
                ->visible(fn () => $this->getOwnerRecord()->beverage_type === MaintenanceSchedule::BEVERAGE_ACQUA),
            Forms\Components\Textarea::make('note')->label('Note')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('descrizione')
            ->defaultSort('data', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('data')->label('Data')->date()->sortable(),
                Tables\Columns\IconColumn::make('filtro_sostituito')
                    ->label('Filtro sostituito')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('macchina')
                    ->label('Macchina')
                    // Qui siamo gia' nel contesto di un piano preciso (la
                    // sua birra/vino/... e le vie sono nell'hero sopra): a
                    // differenza di machineLabel() non torniamo al riepilogo
                    // generico su tutto il parco macchine del cliente quando
                    // la visita non specifica una macchina (era fuorviante,
                    // es. mostrava "Impianto Vino" anche su un piano birra).
                    // Un valore qui ha senso solo per segnalare l'eccezione:
                    // "questa volta ho lavato solo questa macchina".
                    ->state(fn (Lavaggio $record) => $record->machineUnit ? $record->machineUnit->display_name.' — '.$record->machineUnit->serial_number : null)
                    ->placeholder('—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('fatturare_a')
                    ->label('Fatturare a')
                    ->state(fn (Lavaggio $record) => $record->billingLabel())
                    ->wrap(),
                Tables\Columns\TextColumn::make('descrizione')->label('Descrizione')->searchable(),
                Tables\Columns\TextColumn::make('note')->label('Note')->limit(50)->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['customer_id'] = $this->getOwnerRecord()->customer_id;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('rapportino')
                    ->label('Rapportino')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->url(fn (Lavaggio $record) => self::serviceReportCreateUrl($record)),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
