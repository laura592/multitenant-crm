<?php

namespace App\Filament\Resources\ProdottoCaffeResource\Pages;

use App\Filament\Resources\ProdottoCaffeResource;
use Filament\Resources\Pages\ListRecords;

class ListProdottiCaffe extends ListRecords
{
    protected static string $resource = ProdottoCaffeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
