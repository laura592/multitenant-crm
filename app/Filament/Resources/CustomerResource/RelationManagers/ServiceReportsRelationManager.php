<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\ServiceReportResource;
use App\Models\ServiceReport;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceReports';

    protected static ?string $title = 'Rapportini';

    protected static ?string $modelLabel = 'Rapportino';

    // Come LavaggiRelationManager: la scheda cliente si usa quasi sempre in
    // visualizzazione, non sulla /edit dedicata, e Filament rende di default
    // sola-lettura le RelationManager sulla pagina "Visualizza".
    public function isReadOnly(): bool
    {
        return false;
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
                    ->url(fn () => ServiceReportResource::getUrl('create', ['customer_id' => $this->getOwnerRecord()->id], tenant: $this->getOwnerRecord()->tenant)),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('intervention_date')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('macchina')
                    ->label('Macchina')
                    ->state(fn (ServiceReport $record) => $record->machineUnit?->display_name ?? $record->machine_model_name)
                    ->placeholder('—')
                    ->wrap(),
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
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (ServiceReport $record) => ServiceReportResource::getUrl('view', ['record' => $record->id], tenant: $record->tenant)),
            ])
            ->emptyStateHeading('Nessun rapportino registrato')
            ->emptyStateDescription('Crea il primo rapportino con "Nuovo rapportino".');
    }
}
