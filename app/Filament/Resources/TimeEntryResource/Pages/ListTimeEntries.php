<?php

namespace App\Filament\Resources\TimeEntryResource\Pages;

use App\Filament\Pages\RiepilogoOre;
use App\Filament\Resources\TimeEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTimeEntries extends ListRecords
{
    protected static string $resource = TimeEntryResource::class;

    // Filament ordina i gruppi in 'asc' di default, anche se il
    // defaultSort della tabella e' 'desc': senza questo i mesi comparivano
    // dal piu' vecchio al piu' recente mentre le righe dentro ogni mese
    // erano ordinate al contrario, rendendo confusa la navigazione tra le
    // pagine.
    public ?string $tableGroupingDirection = 'desc';

    protected function getHeaderActions(): array
    {
        return [
            // Il riepilogo sono queste stesse timbrature sommate per
            // persona e per giorno: sta qui, non nel menu.
            Actions\Action::make('riepilogo')
                ->label('Riepilogo ore')
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray')
                ->visible(fn () => RiepilogoOre::canAccess())
                ->url(fn () => RiepilogoOre::getUrl()),
            Actions\CreateAction::make()
                ->extraAttributes(['data-tour' => 'time-entries-create']),
        ];
    }
}
