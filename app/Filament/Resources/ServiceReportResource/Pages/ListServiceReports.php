<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
use App\Filament\Pages\ClientiVicini;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListServiceReports extends ListRecords
{
    protected static string $resource = ServiceReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('clientiVicini')
                ->label('Cliente più vicino')
                ->icon('heroicon-o-map-pin')
                ->color('gray')
                ->url(fn () => ClientiVicini::getUrl()),
            Actions\CreateAction::make(),
        ];
    }
}
