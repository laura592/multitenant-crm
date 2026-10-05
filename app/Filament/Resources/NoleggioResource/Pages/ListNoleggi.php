<?php

namespace App\Filament\Resources\NoleggioResource\Pages;

use App\Filament\Resources\NoleggioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNoleggi extends ListRecords
{
    protected static string $resource = NoleggioResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
