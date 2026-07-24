<?php

namespace App\Filament\Resources\ComodatoMacchinaResource\Pages;

use App\Filament\Resources\ComodatoMacchinaResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewComodatoMacchina extends ViewRecord
{
    protected static string $resource = ComodatoMacchinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
