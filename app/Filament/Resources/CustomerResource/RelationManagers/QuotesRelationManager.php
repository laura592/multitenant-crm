<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class QuotesRelationManager extends RelationManager
{
    protected static string $relationship = 'quotes';

    protected static ?string $title = 'Storico preventivi';

    protected static ?string $modelLabel = 'Preventivo';

    // Come ServiceReportsRelationManager: la scheda cliente si usa quasi
    // sempre in visualizzazione, non sulla /edit dedicata, e Filament rende
    // di default sola-lettura le RelationManager sulla pagina "Visualizza".
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('number', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('nuovo_preventivo')
                    ->label('Nuovo preventivo')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => QuoteResource::getUrl('create', ['customer_id' => $this->getOwnerRecord()->id], tenant: $this->getOwnerRecord()->tenant)),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('date')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => QuoteResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => QuoteResource::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR')->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('vedi_preventivo')
                    ->label('Vedi')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Quote $record) => QuoteResource::getUrl('view', ['record' => $record->id], tenant: $record->tenant)),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(fn (Quote $record) => route('quotes.pdf', $record))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Nessun preventivo registrato')
            ->emptyStateDescription('Crea il primo preventivo con "Nuovo preventivo".');
    }
}
