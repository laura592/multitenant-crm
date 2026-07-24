<?php

namespace App\Filament\Resources\QuoteGroupResource\RelationManagers;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class QuotesRelationManager extends RelationManager
{
    protected static string $relationship = 'quotes';

    protected static ?string $title = 'Soluzioni alternative';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => QuoteResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => QuoteResource::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR'),
                Tables\Columns\TextColumn::make('date')->label('Data')->date(),
            ])
            ->headerActions([
                // Niente CreateAction standard: la creazione di un preventivo
                // passa dal wizard completo di QuoteResource (macchina,
                // opzioni, ecc.), non da un mini-form qui che duplicherebbe
                // male quel flusso. "group" in query string fa si' che
                // QuoteResource::form() precompili e blocchi il cliente su
                // quello dell'offerta.
                Tables\Actions\Action::make('newQuote')
                    ->label('Aggiungi soluzione')
                    ->icon('heroicon-o-plus')
                    ->url(fn (RelationManager $livewire) => QuoteResource::getUrl('create').'?group='.$livewire->getOwnerRecord()->getKey()),
                Tables\Actions\Action::make('attachExisting')
                    ->label('Collega preventivo esistente')
                    ->icon('heroicon-o-link')
                    ->form([
                        \Filament\Forms\Components\Select::make('quote_id')
                            ->label('Preventivo')
                            ->options(function (RelationManager $livewire) {
                                $group = $livewire->getOwnerRecord();

                                return Quote::query()
                                    ->where('customer_id', $group->customer_id)
                                    ->where(function ($query) use ($group) {
                                        $query->whereNull('quote_group_id')
                                            ->orWhere('quote_group_id', '!=', $group->getKey());
                                    })
                                    ->orderByDesc('date')
                                    ->get()
                                    ->mapWithKeys(fn (Quote $quote) => [$quote->getKey() => $quote->number.' — '.($quote->total !== null ? number_format($quote->total, 2, ',', '.').' €' : '')])
                                    ->toArray();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        Quote::query()
                            ->whereKey($data['quote_id'])
                            ->update(['quote_group_id' => $livewire->getOwnerRecord()->getKey()]);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Apri')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Quote $record) => QuoteResource::getUrl('edit', ['record' => $record])),
                Tables\Actions\Action::make('removeFromGroup')
                    ->label('Sgancia dalla offerta')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('La soluzione non viene cancellata, resta autonoma fuori dall\'offerta globale.')
                    ->action(fn (Quote $record) => $record->update(['quote_group_id' => null])),
            ]);
    }
}
