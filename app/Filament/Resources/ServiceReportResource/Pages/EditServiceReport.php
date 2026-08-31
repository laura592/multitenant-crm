<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Support\Rapportini\LavaggioFields;
use App\Filament\Concerns\RedirectsCancelToView;
use App\Filament\Resources\ServiceReportResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditServiceReport extends EditRecord
{
    use RedirectsCancelToView;

    protected static string $resource = ServiceReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * ServiceReportPolicy::update() gia' nega la modifica quando isLocked()
     * e' vero, ma Gate::before() in AppServiceProvider (is_super_admin
     * bypassa Shield/spatie ovunque) scavalca quel controllo per lo staff con
     * quel flag. Il blocco deve valere per chiunque, senza eccezioni:
     * ripetuto qui in modo indipendente dal sistema di permessi, sia su
     * mount() (URL diretta) sia su ogni hydrate() (il record potrebbe
     * diventare "bloccato" mentre la pagina e' gia' aperta, es. invio a
     * gestionale completato altrove, o stato segnato "completato").
     */
    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_if($this->getRecord()->isLocked(), 403);
    }

    /**
     * I toggle "Chiamata"/"Manodopera"/"Lavaggio eseguito" e "Numero vie
     * lavate" del form (ServiceReportResource, sezione "Ricambi/materiali
     * utilizzati") sono campi di comodo, non colonne reali: sul fill() di un
     * edit i loro ->default() non scattano (vedi ServiceReportResource::
     * resolveLavaggioShortcutDefaults()), quindi vanno iniettati qui, prima
     * che il form li azzeri, per riflettere le righe
     * CHIVE/CHIORD/ORE/LAV2/ULTVIA gia' presenti sul rapportino.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $defaults = LavaggioFields::resolveLavaggioShortcutDefaults($this->getRecord());

        return [
            ...$data,
            'add_chiamata_material' => $defaults['chiamata_key'] !== null,
            '_chiamata_material_key' => $defaults['chiamata_key'],
            'add_manodopera_material' => $defaults['manodopera_key'] !== null,
            '_manodopera_material_key' => $defaults['manodopera_key'],
            '_lavaggio_vie_eseguito' => $defaults['lavaggio_base_key'] !== null,
            // Colonna vera, gia' presente in $data: la si sovrascrive lo
            // stesso perche' resolveLavaggioShortcutDefaults() la ritorna
            // tale e quale quando c'e', e col ripiego calcolato dalle righe
            // quando e' vuota (rapportini vecchi o importati da Eureka).
            'lavaggio_vie_count' => $defaults['vie_count'],
            '_lavaggio_base_material_key' => $defaults['lavaggio_base_key'],
            '_lavaggio_ult_material_key' => $defaults['lavaggio_ult_key'],
            'lavaggio_impianti' => LavaggioFields::resolveLavaggioImpiantiDefaults($this->getRecord()),
        ];
    }

    /**
     * lavaggio_impianti non e' una colonna reale (vedi il campo sul form):
     * estratto qui prima del save() e riapplicato in afterSave(), stesso
     * meccanismo di CreateServiceReport.
     */
    protected array $lavaggioImpianti = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->lavaggioImpianti = $data['lavaggio_impianti'] ?? [];
        unset($data['lavaggio_impianti']);

        return $data;
    }

    /**
     * Stesso motivo di CreateServiceReport::afterCreate(): applica la
     * selezione esplicita dei piani e le vie lavate del Repeater, che
     * Filament non salva da solo.
     */
    protected function afterSave(): void
    {
        LavaggioFields::syncLavaggioImpianti($this->getRecord(), $this->lavaggioImpianti);
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
