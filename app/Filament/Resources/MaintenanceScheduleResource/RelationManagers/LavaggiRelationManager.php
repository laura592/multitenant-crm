<?php

namespace App\Filament\Resources\MaintenanceScheduleResource\RelationManagers;

use App\Filament\Resources\MaintenanceScheduleResource;
use App\Filament\Resources\ServiceReportResource;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

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

    // Filament rende di default sola-lettura le RelationManager sulla pagina
    // "Visualizza" (non /edit), nascondendo Delete/Edit/Create nativi anche
    // con ->visible()/->authorize() espliciti a posto - le azioni custom
    // (collega/scollega rapportino) invece restano visibili, perche' non
    // sono di uno dei tipi speciali intercettati da quella regola. Risultato
    // per l'utente: "Scollega" funziona dalla scheda ma "Elimina" no, stessa
    // pagina, senza un motivo visibile. Qui si usa quasi sempre la pagina
    // Visualizza (mai la /edit), quindi meglio disattivare la sola-lettura
    // piuttosto che spostare l'abitudine d'uso - i permessi veri restano
    // comunque quelli di LavaggioPolicy. Vedi thread 2026-08-13.
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function serviceReportCreateUrl(Lavaggio $record): string
    {
        $query = array_filter([
            'customer_id' => $record->customer_id,
            'machine_unit_id' => $record->machine_unit_id,
            'intervention_date' => $record->data?->toDateString(),
            // "Sanificazione" e non "Manutenzione ordinaria": un lavaggio non
            // e' una manutenzione, e ServiceReport::countsAsLavaggio()
            // riconosce esplicitamente anche questo tipo (vedi li').
            'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
            'problem_description' => 'Lavaggio impianto',
            'work_performed' => $record->descrizione,
        ], fn ($value) => filled($value));

        return ServiceReportResource::getUrl('create', tenant: $record->tenant).'?'.http_build_query($query);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            // Un cliente puo' avere piu' piani lavaggio (birra/acqua/vino su
            // impianti diversi): senza indicare qui su quale si sta operando,
            // arrivando da una vista filtrata diversa e' facile non accorgersi
            // subito dell'impianto sbagliato.
            Forms\Components\Placeholder::make('piano_contesto')
                ->label('')
                ->columnSpanFull()
                ->content(function () {
                    $schedule = $this->getOwnerRecord();
                    $impianto = MaintenanceScheduleResource::beverageLabels()[$schedule->beverage_type] ?? 'impianto non specificato';

                    return new HtmlString(
                        '<div class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">'
                            .'Stai registrando un lavaggio per: <strong>'.e($impianto).'</strong> presso <strong>'.e(DisplayName::titleCase($schedule->customer?->full_name)).'</strong>.'
                            .'</div>'
                    );
                }),
            Forms\Components\Select::make('machine_unit_id')
                ->label('Macchina')
                ->relationship('machineUnit', 'serial_number', fn ($query) => $query
                    ->where('current_customer_id', $this->getOwnerRecord()->customer_id))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                ->searchable()
                ->preload()
                ->helperText('Lascia vuoto se la visita ha lavato tutti gli impianti (il caso normale). Seleziona la macchina solo se questa volta ne e\' stato lavato uno solo.'),
            Forms\Components\DatePicker::make('data')->label('Data')->required()->default(now()),
            Forms\Components\TextInput::make('lines_washed')
                ->label('Vie lavate')
                ->numeric()
                ->minValue(0)
                ->default(fn () => $this->getOwnerRecord()->lines_count)
                ->helperText(fn () => $this->getOwnerRecord()->lines_count
                    ? 'Vie previste su questo piano: '.$this->getOwnerRecord()->lines_count.'. Cambia il numero se questa volta ne hai lavate meno/di piu\'.'
                    : 'Non rilevante per questo impianto (es. acqua).'),
            Forms\Components\TextInput::make('descrizione')
                ->label('Note')
                ->helperText('Es. "apertura", "chiusura stagionale". Il conteggio vie va nel campo sopra.')
                ->required()
                ->maxLength(255)
                ->default('Lavaggio impianto'),
            Forms\Components\Toggle::make('filtro_sostituito')
                ->label('Filtro sostituito in questa visita')
                ->helperText('Impianti acqua: segna quando il filtro viene cambiato, serve a calcolare la prossima scadenza del piano.')
                ->visible(fn () => $this->getOwnerRecord()->beverage_type === MaintenanceSchedule::BEVERAGE_ACQUA),
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
                Tables\Columns\TextColumn::make('fatturare_a')
                    ->label('Fatturare a')
                    ->state(fn (Lavaggio $record) => $record->billingLabel())
                    ->wrap(),
                Tables\Columns\TextColumn::make('descrizione')->label('Note')->searchable(),
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
                // Speculare all'azione di riga "collega_rapportino", ma qui in
                // testata: senza, per collegare un rapportino gia' esistente a
                // un piano che non ha ancora nessuna riga lavaggio bisognava
                // prima crearne una vuota a mano e poi agganciarci il
                // rapportino in un secondo passaggio.
                Tables\Actions\Action::make('collega_rapportino_testata')
                    // Nome diverso dall'azione di riga "collega_rapportino":
                    // due azioni con lo stesso nome nella stessa tabella
                    // (header + row) confondono il dispatch lato Livewire, il
                    // click sembra non fare nulla (nessuna richiesta arriva
                    // al server, nessun errore nei log).
                    ->label('Collega rapportino')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->form(fn () => [
                        Forms\Components\Select::make('service_report_id')
                            ->label('Rapportino')
                            ->options(ServiceReport::query()
                                ->where('customer_id', $this->getOwnerRecord()->customer_id)
                                ->whereNotIn('id', Lavaggio::query()
                                    ->where('maintenance_schedule_id', $this->getOwnerRecord()->id)
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
                    ->action(function (array $data) {
                        $report = ServiceReport::find($data['service_report_id']);
                        $owner = $this->getOwnerRecord();

                        Lavaggio::create([
                            'tenant_id' => $report->tenant_id,
                            'customer_id' => $owner->customer_id,
                            'maintenance_schedule_id' => $owner->id,
                            'service_report_id' => $report->id,
                            'machine_unit_id' => $report->machine_unit_id,
                            'data' => $report->intervention_date,
                            'descrizione' => "Generato da rapportino {$report->number}",
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('rapportino')
                    ->label(fn (?Lavaggio $record) => $record?->service_report_id ? 'Vedi' : 'Crea rapportino')
                    // Icona occhiello per "Vedi", coerente con ViewAction usato
                    // ovunque nel resto dell'app per le azioni di sola visione.
                    ->icon(fn (?Lavaggio $record) => $record?->service_report_id ? 'heroicon-o-eye' : 'heroicon-o-plus')
                    ->color('gray')
                    ->url(fn (?Lavaggio $record) => $record?->service_report_id
                        ? ServiceReportResource::getUrl('view', ['record' => $record->service_report_id], tenant: $record->tenant)
                        : ($record ? self::serviceReportCreateUrl($record) : null)),
                Tables\Actions\Action::make('collega_rapportino')
                    // Copre il caso di un lavaggio inserito a mano il cui
                    // rapportino esiste gia' nel sistema (es. creato a parte,
                    // o importato da Eureka) invece di doverne creare uno
                    // nuovo duplicato con "Crea rapportino".
                    ->label('Collega rapportino')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (?Lavaggio $record) => $record && ! $record->service_report_id)
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
                Tables\Actions\Action::make('scollega_rapportino')
                    // Speculare a "collega_rapportino": stacca il rapportino
                    // da questa riga (es. collegamento sbagliato, o
                    // duplicato con un piano fratello) senza cancellare la
                    // riga lavaggio - resta come voce manuale, ricollegabile
                    // dopo al rapportino giusto.
                    ->label('Scollega')
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (?Lavaggio $record) => $record && $record->service_report_id)
                    ->action(fn (Lavaggio $record) => $record->update([
                        'service_report_id' => null,
                    ])),
                Tables\Actions\ActionGroup::make([
                    // Anche su una riga generata da rapportino e' ormai sicuro
                    // modificare la descrizione: ServiceReport::syncGeneratedLavaggi()
                    // non la rigenera piu' se e' gia' stata personalizzata (solo le
                    // righe ancora col placeholder "Generato da rapportino ..."
                    // vengono riscritte al prossimo salvataggio del rapportino).
                    Tables\Actions\EditAction::make(),
                    // A differenza di Edit, cancellare una riga collegata a un
                    // rapportino e' sicuro (tocca solo questa riga Lavaggio, non
                    // il rapportino) e serve: es. un doppione su un piano fratello
                    // sbagliato (vedi rimosso "Rimuovi duplicati con piano
                    // fratello", troppo aggressivo) va ora tolto qui a mano, riga
                    // per riga, dopo aver verificato che sia davvero un errore.
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
