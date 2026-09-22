<?php

namespace App\Filament\Resources\MachineUnitResource\RelationManagers;

use App\Models\Customer;
use App\Models\MachineUnitPlacement;
use App\Support\DisplayName;
use App\Support\Macchine\EliminaPosizionamento;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

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
                Tables\Columns\TextColumn::make('pagato_da')->label('Pagato da')
                    ->state(function (MachineUnitPlacement $record): ?string {
                        $pagante = $record->billingCustomer
                            ?? ($record->eureka_billing_customer_code
                                ? Customer::query()->where('gestionale_code', $record->eureka_billing_customer_code)->first()
                                : null);

                        return $pagante && $pagante->id !== $record->customer_id ? DisplayName::customerOption($pagante) : null;
                    })
                    ->placeholder(fn (MachineUnitPlacement $record) => $record->customer_id ? 'il cliente' : '—'),
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
                            ->helperText('Lascia vuoto se pagava il cliente stesso.')
                            ->options(fn () => Customer::query()->orderBy('company_name')->get()->mapWithKeys(
                                fn (Customer $c) => [$c->id => DisplayName::customerOption($c) ?: 'Cliente senza nome']
                            ))
                            ->searchable()
                            ->live()
                            ->hint(fn (?string $state) => $record->avvisoPaganteEureka($state))
                            ->hintColor('warning')
                            ->hintIcon(fn (?string $state) => $record->avvisoPaganteEureka($state) ? 'heroicon-o-exclamation-triangle' : null),
                    ])
                    ->modalHeading(fn (MachineUnitPlacement $record) => 'Chi pagava presso '.DisplayName::customerOption($record->customer))
                    ->action(function (MachineUnitPlacement $record, array $data) {
                        $record->cambiaPagante(($data['billing_customer_id'] ?? null) ? Customer::find($data['billing_customer_id']) : null);

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
