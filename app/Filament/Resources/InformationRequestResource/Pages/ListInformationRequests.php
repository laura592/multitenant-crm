<?php

namespace App\Filament\Resources\InformationRequestResource\Pages;

use App\Filament\Resources\InformationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInformationRequests extends ListRecords
{
    protected static string $resource = InformationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
