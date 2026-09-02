<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Il modulo che il cliente deve firmare, gia' compilato con quello
            // che sappiamo di lui: vedi App\Support\Pdf\SchedaAnagraficaPdf.
            Actions\Action::make('scheda_anagrafica')
                ->label('Scheda anagrafica')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('customers.scheda-anagrafica', $this->record)),
            Actions\EditAction::make(),
        ];
    }
}
