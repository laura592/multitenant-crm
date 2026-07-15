<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Resources\QuoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    protected function afterCreate(): void
    {
        $this->record->updateTotal();
    }

    /**
     * Dopo la creazione (cliente/data/stato), si passa subito alla modifica
     * con il wizard di configurazione macchina già aperto: è il flusso
     * principale per aggiungere una macchina al preventivo, non un'azione
     * secondaria da scoprire da soli.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]).'?openWizard=1';
    }
}
