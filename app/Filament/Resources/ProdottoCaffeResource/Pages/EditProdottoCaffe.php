<?php

namespace App\Filament\Resources\ProdottoCaffeResource\Pages;

use App\Filament\Resources\ProdottoCaffeResource;
use Filament\Resources\Pages\EditRecord;

class EditProdottoCaffe extends EditRecord
{
    protected static string $resource = ProdottoCaffeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}
