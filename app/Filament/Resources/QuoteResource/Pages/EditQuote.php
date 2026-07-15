<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Actions\ConfigureMachineAction;
use App\Filament\Resources\QuoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfigureMachineAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (! request()->boolean('openWizard')) {
            return;
        }

        // Le header actions vengono normalmente messe in cache da
        // bootedInteractsWithHeaderActions(), che nel ciclo di vita Livewire
        // gira dopo mount(): a questo punto l'azione "configureMachine" non
        // sarebbe ancora trovabile per nome. Si forza la cache subito.
        $this->cacheHeaderActions();
        $this->mountAction('configureMachine');
    }

    protected function afterSave(): void
    {
        $this->record->updateTotal();
    }

    /**
     * Il wizard "Configura macchina" crea le righe (quoteProducts) scrivendo
     * direttamente sul modello, fuori dal form della pagina: senza questo, il
     * repeater "Righe preventivo" resta con lo stato caricato al mount finche'
     * non si ricarica manualmente la pagina (docs/architecture.md §11.2).
     */
    public function refreshAfterMachineConfigured(): void
    {
        $this->fillForm();
    }
}
