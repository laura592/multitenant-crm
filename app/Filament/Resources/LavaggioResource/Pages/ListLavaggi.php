<?php

namespace App\Filament\Resources\LavaggioResource\Pages;

use App\Filament\Resources\LavaggioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLavaggi extends ListRecords
{
    protected static string $resource = LavaggioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
