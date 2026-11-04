<?php

namespace App\Filament\Resources\InformationRequestResource\Pages;

use App\Filament\Pages\RichiesteSullaMappa;
use App\Filament\Resources\InformationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInformationRequests extends ListRecords
{
    protected static string $resource = InformationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // La mappa e' lo stesso elenco guardato da sopra: sta qui e non
            // nel menu, perche' e' qui che ti viene in mente di volerla.
            Actions\Action::make('mappa')
                ->label('Sulla mappa')
                ->icon('heroicon-o-map')
                ->color('gray')
                ->visible(fn () => RichiesteSullaMappa::canAccess())
                ->url(fn () => RichiesteSullaMappa::getUrl()),

            Actions\CreateAction::make()
                ->extraAttributes(['data-tour' => 'information-requests-create']),
        ];
    }
}
