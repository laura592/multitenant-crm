<?php

namespace App\Filament\Resources\OffertaCaffeResource\Pages;

use App\Filament\Resources\OffertaCaffeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOffertaCaffe extends CreateRecord
{
    protected static string $resource = OffertaCaffeResource::class;

    // Dopo il salvataggio si resta sull'offerta, dove ci sono PDF e Invia.
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
