<?php

namespace App\Filament\Resources\InterventoProgrammatoResource\Pages;

use App\Filament\Resources\InterventoProgrammatoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInterventoProgrammato extends CreateRecord
{
    protected static string $resource = InterventoProgrammatoResource::class;

    protected function getRedirectUrl(): string
    {
        // Si mette in programma un lavoro dopo l'altro: tornare all'elenco
        // (e non alla scheda appena creata) e' quello che serve a chi sta
        // ricopiando il foglio.
        return $this->getResource()::getUrl('index');
    }
}
