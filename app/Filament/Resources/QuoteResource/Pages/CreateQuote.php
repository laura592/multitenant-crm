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
     * Dopo la creazione si passa alla modifica: l'apertura automatica del
     * wizard "Configura macchina" e' stata tolta su richiesta esplicita
     * (l'utente vuole aprirlo lui quando serve, non ritrovarselo aperto).
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
