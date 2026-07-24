<?php

namespace App\Filament\Resources\ComodatoMacchinaResource\Pages;

use App\Filament\Resources\ComodatoMacchinaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditComodatoMacchina extends EditRecord
{
    protected static string $resource = ComodatoMacchinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
