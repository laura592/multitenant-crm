<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\InformationRequestResource;
use App\Models\InformationRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Le richieste informazioni del cliente, accanto agli altri storici.
 *
 * Arrivano dal modulo del sito e sono l'inizio di quasi ogni trattativa:
 * averle qui vuol dire aprire la scheda e sapere di cosa aveva chiesto,
 * invece di cercarlo nell'elenco generale (Laura, 07/10/2026).
 */
class RichiesteRelationManager extends RelationManager
{
    protected static string $relationship = 'informationRequests';

    protected static ?string $title = 'Storico richieste';

    protected static ?string $modelLabel = 'Richiesta';

    // Come QuotesRelationManager: la scheda cliente si usa quasi sempre in
    // visualizzazione, e Filament di default renderebbe tutto sola lettura.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessuna richiesta da questo cliente')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Arrivata il')->date('d/m/Y')->sortable(),
                // Il testo della richiesta e' la ragione per cui si apre
                // questa pagina: troncato, intero nel tooltip, non mandato
                // a capo su quattro righe.
                Tables\Columns\TextColumn::make('request_details')
                    ->label('Richiesta')
                    ->limit(60)
                    ->tooltip(fn (InformationRequest $record) => $record->request_details)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => InformationRequestResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => InformationRequestResource::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('appointment_at')
                    ->label('Appuntamento')->dateTime('d/m/Y H:i')->sortable()
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('vedi')
                    ->label('Vedi')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (InformationRequest $record) => InformationRequestResource::getUrl('edit', ['record' => $record->id], tenant: $record->tenant)),
            ]);
    }
}
