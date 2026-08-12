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
     * I toggle "Chiamata"/"Lavaggio eseguito" e "Numero vie lavate" del form
     * (ServiceReportResource, sezione "Ricambi/materiali utilizzati") sono
     * campi di comodo, non colonne reali: sul fill() di un edit i loro
     * ->default() non scattano (vedi ServiceReportResource::
     * resolveLavaggioShortcutDefaults()), quindi vanno iniettati qui, prima
     * che il form li azzeri, per riflettere le righe CHIVE/CHIORD/LAV2/ULTVIA
     * gia' presenti sul rapportino.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $defaults = ServiceReportResource::resolveLavaggioShortcutDefaults($this->getRecord());

        return [
            ...$data,
            'add_chiamata_material' => $defaults['chiamata_key'] !== null,
            '_chiamata_material_key' => $defaults['chiamata_key'],
            '_lavaggio_vie_eseguito' => $defaults['lavaggio_base_key'] !== null,
            '_lavaggio_vie_count' => $defaults['vie_count'],
            '_lavaggio_base_material_key' => $defaults['lavaggio_base_key'],
            '_lavaggio_ult_material_key' => $defaults['lavaggio_ult_key'],
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
