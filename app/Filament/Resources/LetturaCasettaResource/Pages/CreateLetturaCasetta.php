<?php

namespace App\Filament\Resources\LetturaCasettaResource\Pages;

use App\Filament\Resources\LetturaCasettaResource;
use App\Models\LetturaCasetta;
use App\Support\Casette\AvvisoFiltro;
use Filament\Resources\Pages\CreateRecord;

class CreateLetturaCasetta extends CreateRecord
{
    protected static string $resource = LetturaCasettaResource::class;

    protected function getRedirectUrl(): string
    {
        // Il tecnico che fa il giro delle due casette ne scrive una dopo
        // l'altra: torna all'elenco, non sulla scheda appena salvata.
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $lettura = $this->getRecord();

        if ($lettura instanceof LetturaCasetta) {
            AvvisoFiltro::perLettura($lettura);
        }
    }
}
