<?php

namespace App\Filament\Resources\AgenteResource\Pages;

use App\Filament\Resources\AgenteResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageAgenti extends ManageRecords
{
    protected static string $resource = AgenteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuovo agente'),
        ];
    }
}
