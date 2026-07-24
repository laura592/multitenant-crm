<?php

namespace App\Filament\Resources\QuoteGroupResource\Pages;

use App\Filament\Resources\QuoteGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuoteGroup extends CreateRecord
{
    protected static string $resource = QuoteGroupResource::class;

    /**
     * Dopo la creazione si atterra' subito sull'edit, pronti ad aggiungere i
     * preventivi alternativi nel tab "Preventivi" (stesso pattern di
     * QuoteResource\Pages\CreateQuote).
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
