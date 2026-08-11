<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditServiceReport extends EditRecord
{
    protected static string $resource = ServiceReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Titolo/corpo piu' espliciti del default Filament ("Salvato" e basta):
     * il resto (persistenza, colore pieno) e' un default globale, vedi
     * Notification::configureUsing() in AppServiceProvider.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Rapportino salvato')
            ->body('Le modifiche sono state salvate.');
    }
}
