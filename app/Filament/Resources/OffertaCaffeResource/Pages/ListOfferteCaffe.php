<?php

namespace App\Filament\Resources\OffertaCaffeResource\Pages;

use App\Filament\Resources\OffertaCaffeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOfferteCaffe extends ListRecords
{
    protected static string $resource = OffertaCaffeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuova offerta caffè'),
        ];
    }
}
