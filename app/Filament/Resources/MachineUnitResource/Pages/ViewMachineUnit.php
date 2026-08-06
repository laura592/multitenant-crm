<?php

namespace App\Filament\Resources\MachineUnitResource\Pages;

use App\Filament\Resources\MachineUnitResource;
use App\Filament\Resources\ServiceReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMachineUnit extends ViewRecord
{
    protected static string $resource = MachineUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Stessa azione "Crea rapportino" gia' presente nella tabella
            // (MachineUnitResource::table()): qui serve altrettanto, perche'
            // il click riga ora apre la view invece dell'edit e da li' non
            // si passa piu' dal menu azioni della tabella.
            Actions\Action::make('create_service_report')
                ->label('Crea rapportino')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->visible(fn () => $this->record->current_customer_id !== null)
                ->url(fn () => ServiceReportResource::getUrl('create', [
                    'machine_unit_id' => $this->record->id,
                    'customer_id' => $this->record->current_customer_id,
                ])),
            Actions\EditAction::make(),
        ];
    }
}
