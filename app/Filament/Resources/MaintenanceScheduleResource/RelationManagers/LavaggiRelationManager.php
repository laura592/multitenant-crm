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
                // Un lavaggio generato automaticamente da ServiceReport::syncMaintenanceSchedule()
                // (rapportino di manutenzione ordinaria chiuso, o testo libero "lavagg/puliz/sanific"
                // sullo storico importato) ha service_report_id valorizzato: qui si vede quale, con
                // link diretto — altrimenti e' un lavaggio inserito a mano in questo registro.
                Tables\Columns\TextColumn::make('serviceReport.number')
                    ->label('Rapportino')
                    ->placeholder('— (inserito a mano)')
                    ->url(fn (Lavaggio $record) => $record->service_report_id
                        ? ServiceReportResource::getUrl('view', ['record' => $record->service_report_id], tenant: $record->tenant)
                        : null)
                    ->color(fn (Lavaggio $record) => $record->service_report_id ? 'primary' : 'gray'),
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
                    ->label(fn (Lavaggio $record) => $record->service_report_id ? 'Vedi rapportino' : 'Crea rapportino')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->url(fn (Lavaggio $record) => $record->service_report_id
                        ? ServiceReportResource::getUrl('view', ['record' => $record->service_report_id], tenant: $record->tenant)
                        : self::serviceReportCreateUrl($record)),
                Tables\Actions\Action::make('collega_rapportino')
                    // Copre il caso di un lavaggio inserito a mano il cui
                    // rapportino esiste gia' nel sistema (es. creato a parte,
                    // o importato da Eureka) invece di doverne creare uno
                    // nuovo duplicato con "Crea rapportino".
                    ->label('Collega rapportino')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (Lavaggio $record) => ! $record->service_report_id)
                    ->form(fn (Lavaggio $record) => [
                        Forms\Components\Select::make('service_report_id')
                            ->label('Rapportino')
                            ->options(ServiceReport::query()
                                ->where('customer_id', $record->customer_id)
                                ->whereNotIn('id', Lavaggio::query()
                                    ->where('maintenance_schedule_id', $record->maintenance_schedule_id)
                                    ->whereNotNull('service_report_id')
                                    ->pluck('service_report_id'))
                                ->orderByDesc('intervention_date')
                                ->get()
                                ->mapWithKeys(fn (ServiceReport $report) => [
                                    $report->id => $report->number.' — '.$report->intervention_date?->format('d/m/Y'),
                                ]))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(fn (Lavaggio $record, array $data) => $record->update([
                        'service_report_id' => $data['service_report_id'],
                    ])),
                Tables\Actions\EditAction::make()
                    // Un lavaggio generato da un rapportino va modificato li' (il
                    // rapportino), non qui: editarlo direttamente verrebbe
                    // silenziosamente sovrascritto al prossimo salvataggio del
                    // rapportino collegato (syncMaintenanceSchedule() lo rigenera).
                    ->visible(fn (Lavaggio $record) => ! $record->service_report_id),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Lavaggio $record) => ! $record->service_report_id),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
