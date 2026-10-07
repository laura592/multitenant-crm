<?php

namespace App\Filament\Resources\InformationRequestResource\RelationManagers;

use App\Filament\Resources\NoleggioResource;
use App\Models\Noleggio;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * I noleggi nati dai preventivi di questa richiesta.
 *
 * Accanto ai preventivi, perche' una richiesta puo' finire in un acquisto o
 * in un noleggio e guardando la richiesta si vuole sapere com'e' andata.
 */
class NoleggiRelationManager extends RelationManager
{
    protected static string $relationship = 'noleggi';

    protected static ?string $title = 'Noleggi collegati';

    protected static ?string $modelLabel = 'Noleggio';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Nessun noleggio nato da questa richiesta')
            ->emptyStateDescription('Compaiono qui i noleggi creati a partire da un preventivo di questa richiesta.')
            ->columns([
                Tables\Columns\TextColumn::make('descrizione')
                    ->label('Attrezzatura')
                    ->limit(40)
                    ->tooltip(fn (Noleggio $record) => $record->descrizione)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('canone')
                    ->label('Canone')->money('EUR')
                    ->description(fn (Noleggio $record) => $record->mesi.' mesi'),
                Tables\Columns\TextColumn::make('data_inizio')
                    ->label('Decorrenza')->date('d/m/Y')->placeholder('da fissare'),
                Tables\Columns\TextColumn::make('stato')
                    ->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => Noleggio::statiLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Noleggio::STATO_ATTIVO => 'success',
                        Noleggio::STATO_CHIUSO => 'gray',
                        default => 'warning',
                    }),
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
