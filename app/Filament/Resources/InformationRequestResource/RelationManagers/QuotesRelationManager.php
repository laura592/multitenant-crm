<?php

namespace App\Filament\Resources\InformationRequestResource\RelationManagers;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * I preventivi nati da questa richiesta. Sola lettura: un preventivo si
 * modifica dalla sua pagina, qui serve solo vedere a che punto e' — che e'
 * poi il modo in cui la richiesta "sa" di essere stata preventivata, senza
 * uno stato parallelo da tenere aggiornato a mano.
 */
class QuotesRelationManager extends RelationManager
{
    protected static string $relationship = 'quotes';

    protected static ?string $title = 'Preventivi';

    protected static ?string $modelLabel = 'Preventivo';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('crea_preventivo')
                    ->label('Nuovo preventivo')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => QuoteResource::getUrl('create', [
                        'customer_id' => $this->getOwnerRecord()->customer_id,
                        'information_request_id' => $this->getOwnerRecord()->id,
                    ], tenant: $this->getOwnerRecord()->tenant)),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Numero')
                    ->url(fn (Quote $record) => QuoteResource::getUrl('edit', ['record' => $record->id], tenant: $record->tenant))
                    ->color('primary'),
                Tables\Columns\TextColumn::make('date')->label('Data')->date('d/m/Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => QuoteResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => QuoteResource::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR')->placeholder('—'),
                // L'offerta esiste solo quando i preventivi sono stati
                // raggruppati: senza gruppo la colonna resta vuota, non e' un
                // dato mancante.
                Tables\Columns\TextColumn::make('quoteGroup.number')->label('Offerta')->placeholder('—'),
            ])
            ->emptyStateHeading('Nessun preventivo da questa richiesta')
            ->emptyStateDescription('Usa "Nuovo preventivo": cliente e prodotti di interesse vengono portati dentro da soli.');
    }
}
