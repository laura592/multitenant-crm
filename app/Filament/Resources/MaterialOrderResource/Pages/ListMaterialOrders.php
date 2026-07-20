<?php

namespace App\Filament\Resources\MaterialOrderResource\Pages;

use App\Filament\Resources\MaterialOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaterialOrders extends ListRecords
{
    protected static string $resource = MaterialOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
