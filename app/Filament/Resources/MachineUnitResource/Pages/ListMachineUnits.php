<?php

namespace App\Filament\Resources\MachineUnitResource\Pages;

use App\Filament\Resources\MachineUnitResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMachineUnits extends ListRecords
{
    protected static string $resource = MachineUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
