<?php

namespace App\Filament\Resources\TimeEntryResource\Pages;

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
            Actions\CreateAction::make(),
        ];
    }
}
