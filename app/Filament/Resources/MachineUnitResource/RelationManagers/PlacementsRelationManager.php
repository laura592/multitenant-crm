<?php

namespace App\Filament\Resources\MachineUnitResource\RelationManagers;

use App\Models\Customer;
use App\Models\MachineUnitPlacement;
use App\Support\DisplayName;
use App\Support\Macchine\EliminaPosizionamento;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Storico sola lettura: gli spostamenti si creano solo tramite l'azione
 * "Sposta" sulla lista principale (MachineUnit::moveTo()), mai qui a mano,
 * per non rompere l'invariante "un solo posizionamento aperto alla volta".
 * Uno spostamento sbagliato si toglie da qui con "Elimina", anche se non e'
 * l'ultimo: lo storico si ricuce da solo (EliminaPosizionamento).
 */
class PlacementsRelationManager extends RelationManager
{
    protected static string $relationship = 'placements';

    protected static ?string $title = 'Storico posizionamenti';

    // Sulla pagina "Visualizza" Filament rende le RelationManager di sola
    // lettura: qui l'eliminazione serve proprio dal dettaglio della macchina.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->placeholder('Magazzino')
                    ->formatStateUsing(fn ($state, $record) => DisplayName::customerOption($record->customer)),
                // Chi pagava in quel periodo (22/09/2026). Per le posizioni
                // vecchie si sa solo il codice Eureka: si risolve al volo.
                // "il cliente stesso" e' una scelta esplicita, diversa da
                // "come il cliente": li' vale il pagante dell'anagrafica.
                Tables\Columns\TextColumn::make('pagato_da')->label('Pagato da')
                    ->state(fn (MachineUnitPlacement $record) => $record->paganteInParole())
                    ->color(fn (MachineUnitPlacement $record) => $record->billing_customer_id ? null : 'gray'),
                Tables\Columns\TextColumn::make('placed_at')->label('Dal')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('removed_at')->label('Al')->dateTime('d/m/Y H:i')->placeholder('In corso'),
                Tables\Columns\TextColumn::make('notes')->label('Note')->limit(50)->tooltip(fn ($state) => $state),
            ])
            ->actions([
                // Chi pagava in quel periodo (22/09/2026). Sulla posizione
                // attuale e' lo stesso "Fatturare a" della macchina.
                Tables\Actions\Action::make('cambia_pagante')
                    ->label('Cambia pagante')
                    ->icon('heroicon-o-banknotes')
                    ->color('gray')
                    ->visible(fn (MachineUnitPlacement $record) => $record->customer_id !== null)
                    ->authorize(fn () => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->fillForm(fn (MachineUnitPlacement $record) => ['billing_customer_id' => $record->billing_customer_id])
                    ->form(fn (MachineUnitPlacement $record) => [
                        Select::make('billing_customer_id')
                            ->label('Fatturare a')
                            ->helperText(fn () => 'Vuoto = come dice l\'anagrafica del cliente'
                                .($record->customer?->billingCustomer ? ' (oggi: '.DisplayName::titleCase($record->customer->billingCustomer->company_name).')' : '')
                                .'. Scegli il cliente stesso se per questa macchina paga lui.')
                            ->options(fn () => Customer::query()->orderBy('company_name')->get()->mapWithKeys(
                                fn (Customer $c) => [$c->id => DisplayName::customerOption($c) ?: 'Cliente senza nome']
                            ))
                            ->searchable()
                            ->live()
                            ->hint(fn (?string $state) => $record->avvisoPaganteEureka($state))
                            ->hintColor('warning')
                            ->hintIcon(fn (?string $state) => $record->avvisoPaganteEureka($state) ? 'heroicon-o-exclamation-triangle' : null),
                        // Sulla posizione in corso il cambio ha una data, e il
                        // pagante di prima resta nello storico fino a quel giorno.
                        Toggle::make('correzione')
                            ->label('Era sbagliato dall\'inizio (correggi, senza nuovo periodo)')
                            ->live()
                            ->visible($record->removed_at === null),
                        DatePicker::make('dal')
                            ->label('Paga il nuovo pagante dal')
                            ->default(now()->toDateString())
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->minDate($record->placed_at->copy()->addDay()->toDateString())
                            ->maxDate(now())
                            ->required(fn (Get $get) => ! $get('correzione'))
                            ->visible(fn (Get $get) => $record->removed_at === null && ! $get('correzione'))
                            ->helperText('Fino al giorno prima resta il pagante di adesso: lo vedi nello storico.'),
                    ])
                    ->modalHeading(fn (MachineUnitPlacement $record) => 'Chi pagava presso '.DisplayName::customerOption($record->customer))
                    ->action(function (MachineUnitPlacement $record, array $data) {
                        $dal = ($data['correzione'] ?? false) || empty($data['dal']) ? null : Carbon::parse($data['dal']);
                        $record->cambiaPagante(($data['billing_customer_id'] ?? null) ? Customer::find($data['billing_customer_id']) : null, $dal?->isToday() ? now() : $dal?->startOfDay());

                        Notification::make()->title('Pagante aggiornato')->success()->send();
                    }),
                Tables\Actions\Action::make('elimina')
                    ->label('Elimina')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->authorize(fn () => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Eliminare questo spostamento?')
                    ->modalDescription(fn (MachineUnitPlacement $record) => EliminaPosizionamento::descrizione($record))
                    ->modalSubmitActionLabel('Elimina')
                    ->action(function (MachineUnitPlacement $record) {
                        EliminaPosizionamento::esegui($record);

                        Notification::make()->title('Spostamento eliminato')->success()->send();
                    }),
            ])
            ->defaultSort('placed_at', 'desc');
    }
}
