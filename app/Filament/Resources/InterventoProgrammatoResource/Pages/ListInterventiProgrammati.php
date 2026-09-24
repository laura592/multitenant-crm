<?php

namespace App\Filament\Resources\InterventoProgrammatoResource\Pages;

use App\Filament\Resources\InterventoProgrammatoResource;
use App\Models\InterventoProgrammato;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInterventiProgrammati extends ListRecords
{
    protected static string $resource = InterventoProgrammatoResource::class;

    public function getTitle(): string
    {
        // Le due letture della stessa pagina: l'ufficio prepara il giro, il
        // tecnico legge il suo.
        return auth()->user()?->can('assegna', InterventoProgrammato::class)
            ? 'Programmazione'
            : 'Il mio giro';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Metti in programma'),
        ];
    }
}
