<?php

namespace App\Filament\Resources\LetturaCasettaResource\Pages;

use App\Filament\Resources\LetturaCasettaResource;
use App\Models\LetturaCasetta;
use App\Support\Casette\AvvisoFiltro;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLetturaCasetta extends EditRecord
{
    protected static string $resource = LetturaCasettaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        $lettura = $this->getRecord();

        if ($lettura instanceof LetturaCasetta) {
            AvvisoFiltro::perLettura($lettura);
        }
    }
}
