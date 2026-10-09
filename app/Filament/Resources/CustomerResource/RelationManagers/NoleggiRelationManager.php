<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\NoleggioResource;
use App\Models\Noleggio;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Lo storico dei noleggi del cliente, accanto a quello dei preventivi.
 *
 * Qui si guarda cosa gli e' stato proposto e a che condizioni, non come e'
 * composto il canone: costo d'acquisto, sconto e margini restano sulla
 * scheda del noleggio, dove li legge chi prepara l'offerta.
 */
class NoleggiRelationManager extends RelationManager
{
    protected static string $relationship = 'noleggi';

    protected static ?string $title = 'Storico noleggi';

    protected static ?string $modelLabel = 'Noleggio';

    // Come QuotesRelationManager: la scheda cliente si usa quasi sempre in
    // visualizzazione, e Filament di default renderebbe tutto sola lettura.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun noleggio per questo cliente')
            ->headerActions([
                Tables\Actions\Action::make('nuovo_noleggio')
                    ->label('Nuovo noleggio')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => NoleggioResource::getUrl('create', tenant: $this->getOwnerRecord()->tenant)),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')
                    ->weight('medium')->searchable(),
                Tables\Columns\TextColumn::make('descrizione')
                    ->label('Attrezzatura')
                    ->wrap(false)
                    ->limit(40)
                    ->tooltip(fn (Noleggio $record) => $record->descrizione)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('canone')
                    ->label('Canone')->money('EUR')->sortable()
                    ->description(fn (Noleggio $record) => $record->mesi.' mesi'),
                Tables\Columns\TextColumn::make('data_inizio')
                    ->label('Decorrenza')->date('d/m/Y')->sortable()
                    ->placeholder('da fissare'),
                Tables\Columns\TextColumn::make('stato')
                    ->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => Noleggio::statiLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Noleggio::STATO_ATTIVO => 'success',
                        Noleggio::STATO_INVIATO => 'info',
                        Noleggio::STATO_CHIUSO => 'gray',
                        default => 'warning',
                    }),
                // Quando e' partito al cliente, e se e' partito: la domanda
                // che ci si fa guardando lo storico e' "gliel'abbiamo
                // mandato?".
                Tables\Columns\TextColumn::make('inviato')
                    ->label('Inviato')
                    ->state(fn (Noleggio $record) => $record->emails->first()?->created_at?->format('d/m/Y'))
                    ->description(fn (Noleggio $record) => $record->emails->first()?->recipient_email)
                    ->placeholder('mai'),
            ])
            ->actions([
                Tables\Actions\Action::make('vedi')
                    ->label('Vedi')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Noleggio $record) => NoleggioResource::getUrl('view', ['record' => $record->id], tenant: $record->tenant)),
                Tables\Actions\Action::make('contratto')
                    ->label('Contratto')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->url(fn (Noleggio $record) => route('noleggi.contratto', $record))
                    ->openUrlInNewTab(),
            ]);
    }
}
