<?php

namespace App\Filament\Resources\MaintenanceScheduleResource\RelationManagers;

use App\Filament\Resources\ServiceReportResource;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InterventiRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceReports';

    protected static ?string $title = 'Rapportini';

    protected static ?string $modelLabel = 'Rapportino';

    // Speculare a LavaggiRelationManager: ha senso solo per i piani di
    // manutenzione, i piani lavaggio hanno gia' la loro vista dedicata
    // (i rapportini li' diventano righe Lavaggio).
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === MaintenanceSchedule::TYPE_MANUTENZIONE;
    }

    /**
     * A differenza di Lavaggio, ServiceReport non ha una FK verso il piano
     * (vedi ServiceReport::syncMaintenanceSchedule()): niente da "creare" o
     * "collegare" qui in senso stretto, un rapportino compilato per lo stesso
     * cliente/macchina di questo piano ci finisce dentro da solo. Questo link
     * serve solo a evitare che il tecnico debba uscire da qui e ricercare a
     * mano cliente e macchina sul resource Rapportini - stessa scorciatoia
     * di LavaggiRelationManager::serviceReportCreateUrl().
     */
    public static function serviceReportCreateUrl(MaintenanceSchedule $schedule): string
    {
        $query = array_filter([
            'customer_id' => $schedule->customer_id,
            'machine_unit_id' => $schedule->machine_unit_id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            // Speculare a LavaggiRelationManager::serviceReportCreateUrl(),
            // che prefilla "Lavaggio impianto": senza, work_performed
            // (obbligatorio sul form) restava vuoto a chi partiva da un piano
            // di manutenzione, unico dei due casi a dover scrivere da zero.
            'work_performed' => 'Manutenzione ordinaria',
        ], fn ($value) => filled($value));

        return ServiceReportResource::getUrl('create', tenant: $schedule->tenant).'?'.http_build_query($query);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('intervention_date', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('nuovo_rapportino')
                    ->label('Nuovo rapportino')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => static::serviceReportCreateUrl($this->getOwnerRecord())),
                // Speculare a "collega_rapportino_testata" di LavaggiRelationManager:
                // qui pero' non esiste una riga Lavaggio da agganciare, il
                // rapportino stesso deve avere machine_unit_id = quello del piano
                // (vedi MaintenanceSchedule::serviceReports()) - "collegare" vuol
                // dire correggere quel campo, tipicamente su un rapportino
                // importato da Eureka finito sulla macchina sbagliata.
                Tables\Actions\Action::make('collega_rapportino_testata')
                    // Etichetta diversa da "Collega rapportino" di
                    // LavaggiRelationManager apposta: li' l'azione aggancia un
                    // record (service_report_id su una riga Lavaggio), qui
                    // invece corregge un campo (machine_unit_id) su un
                    // rapportino gia' esistente - stessa parola per due
                    // meccanismi diversi confondeva chi passa da un piano
                    // all'altro.
                    ->label('Assegna rapportino a questa macchina')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->form(fn () => [
                        Forms\Components\Select::make('service_report_id')
                            ->label('Rapportino')
                            ->options(function () {
                                $schedule = $this->getOwnerRecord();

                                return ServiceReport::withoutGlobalScopes()
                                    ->where('customer_id', $schedule->customer_id)
                                    ->where(fn ($q) => $q->whereNull('machine_unit_id')->orWhere('machine_unit_id', '!=', $schedule->machine_unit_id))
                                    ->orderByDesc('intervention_date')
                                    ->get()
                                    ->mapWithKeys(function (ServiceReport $report) {
                                        // La macchina attuale del rapportino in etichetta:
                                        // senza, e' facile ripetere lo stesso errore trovato
                                        // sui Lavaggio storici (collegamento scelto alla cieca
                                        // su un cliente con piu' impianti).
                                        $currentMachine = $report->machineUnit?->display_name ?? $report->machine_model_name;

                                        return [
                                            $report->id => $report->number.' — '.$report->intervention_date?->format('d/m/Y')
                                                .($currentMachine ? " (oggi su: {$currentMachine})" : ' (nessuna macchina)'),
                                        ];
                                    });
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $schedule = $this->getOwnerRecord();
                        $report = ServiceReport::withoutGlobalScopes()->find($data['service_report_id']);
                        $report?->update(['machine_unit_id' => $schedule->machine_unit_id]);
                    }),
            ])
            ->columns([
                // Stesso set di colonne di LavaggiRelationManager (Data,
                // Fatturare a, Descrizione, Rapportino) - le due tabelle
                // vivono sulla stessa pagina piano (manutenzione qui,
                // lavaggio li'), devono leggersi allo stesso modo.
                Tables\Columns\TextColumn::make('intervention_date')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('fatturare_a')
                    ->label('Fatturare a')
                    // Stessa priorita' di Lavaggio::billingLabel(): la
                    // destinazione letta da Eureka su QUESTO rapportino batte
                    // il billing_customer_id generico di macchina/cliente,
                    // quando disponibile.
                    ->state(fn (ServiceReport $record) => DisplayName::titleCase($record->eureka_destinazione_label) ?? DisplayName::titleCase($record->invoiceRecipient()->full_name))
                    ->wrap(),
                // work_performed e' il campo obbligatorio "cosa e' stato
                // fatto" sul rapportino - stesso ruolo di Lavaggio.descrizione
                // (es. "5 vie + apertura").
                Tables\Columns\TextColumn::make('work_performed')->label('Descrizione')->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('number')
                    ->label('Rapportino')
                    ->url(fn (ServiceReport $record) => ServiceReportResource::getUrl('view', ['record' => $record->id], tenant: $record->tenant))
                    ->color('primary'),
                Tables\Columns\TextColumn::make('intervention_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        ServiceReport::TYPE_INSTALLAZIONE => 'Installazione',
                        ServiceReport::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione ord.',
                        ServiceReport::TYPE_MANUTENZIONE_STRAORDINARIA => 'Manutenzione straord.',
                        ServiceReport::TYPE_RIPARAZIONE => 'Riparazione',
                        ServiceReport::TYPE_GARANZIA => 'Garanzia',
                        ServiceReport::TYPE_SANIFICAZIONE => 'Sanificazione',
                        default => $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ServiceReportResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => ServiceReportResource::statusColors()[$state] ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('technician.name')->label('Tecnico')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('vedi_rapportino')
                    ->label('Vedi')
                    // Icona occhiello, coerente con ViewAction usato ovunque
                    // nel resto dell'app per le azioni di sola visione.
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (ServiceReport $record) => ServiceReportResource::getUrl('view', ['record' => $record->id], tenant: $record->tenant)),
                // Speculare a "scollega_rapportino" di LavaggiRelationManager:
                // qui azzera machine_unit_id sul rapportino (unico modo per farlo
                // uscire da questo elenco, vedi MaintenanceSchedule::serviceReports())
                // - toglie il collegamento ovunque, non solo da questo piano, non
                // essendoci una riga di giunzione come per Lavaggio.
                Tables\Actions\Action::make('scollega_rapportino')
                    // Idem sopra: "Scollega macchina" invece di "Scollega"
                    // secco per non sembrare la stessa identica azione di
                    // LavaggiRelationManager (li' stacca un rapportino da una
                    // riga Lavaggio, qui azzera la macchina sul rapportino).
                    ->label('Scollega macchina')
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Toglie la macchina da questo rapportino: uscira\' da questo piano (e da qualsiasi altro piano macchina-specifico), non solo dalla vista corrente.')
                    ->action(fn (ServiceReport $record) => $record->update(['machine_unit_id' => null])),
            ]);
    }
}
