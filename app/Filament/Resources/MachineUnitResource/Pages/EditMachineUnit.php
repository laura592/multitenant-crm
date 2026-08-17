<?php

namespace App\Filament\Resources\MachineUnitResource\Pages;

use App\Filament\Concerns\RedirectsCancelToView;
use App\Filament\Resources\MachineUnitResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMachineUnit extends EditRecord
{
    use RedirectsCancelToView;

    protected static string $resource = MachineUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Stesse azioni gia' presenti nel menu di riga della tabella
            // (MachineUnitResource::table()) e nell'header della view.
            MachineUnitResource::confermaCollegamentoGestionaleAction(Actions\Action::make('conferma_collegamento_gestionale')),
            MachineUnitResource::scartaCollegamentoGestionaleAction(Actions\Action::make('scarta_collegamento_gestionale')),
            MachineUnitResource::cercaEurekaAction(Actions\Action::make('cerca_eureka')),
            MachineUnitResource::createServiceReportAction(Actions\Action::make('create_service_report')),
            MachineUnitResource::spostaAction(Actions\Action::make('sposta')),
            Actions\DeleteAction::make(),
        ];
    }
}
