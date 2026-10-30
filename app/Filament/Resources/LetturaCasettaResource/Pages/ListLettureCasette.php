<?php

namespace App\Filament\Resources\LetturaCasettaResource\Pages;

use App\Filament\Resources\LetturaCasettaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLettureCasette extends ListRecords
{
    protected static string $resource = LetturaCasettaResource::class;

    public function getTitle(): string
    {
        return 'Casette dell\'acqua';
    }

    public function getSubheading(): ?string
    {
        return 'Contatore, incasso e venduto a ogni passaggio. Da qui esce il rendiconto per il Comune.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuova lettura'),
        ];
    }
}
