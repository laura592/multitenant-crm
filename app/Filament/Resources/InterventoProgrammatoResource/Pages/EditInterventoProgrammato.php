<?php

namespace App\Filament\Resources\InterventoProgrammatoResource\Pages;

use App\Filament\Resources\InterventoProgrammatoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInterventoProgrammato extends EditRecord
{
    protected static string $resource = InterventoProgrammatoResource::class;

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
}
