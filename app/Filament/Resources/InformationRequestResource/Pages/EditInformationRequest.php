<?php

namespace App\Filament\Resources\InformationRequestResource\Pages;

use App\Filament\Actions\ContattaCliente;
use App\Filament\Resources\InformationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInformationRequest extends EditRecord
{
    protected static string $resource = InformationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ContattaCliente::perPagina($this->record->customer),
            Actions\DeleteAction::make(),
        ];
    }
}
