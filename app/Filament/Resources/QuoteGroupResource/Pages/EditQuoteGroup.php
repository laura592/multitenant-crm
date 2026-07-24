<?php

namespace App\Filament\Resources\QuoteGroupResource\Pages;

use App\Filament\Resources\QuoteGroupResource;
use App\Filament\Resources\QuoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQuoteGroup extends EditRecord
{
    protected static string $resource = QuoteGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('newQuote')
                ->label('Aggiungi preventivo')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->url(fn () => QuoteResource::getUrl('create').'?group='.$this->record->getKey()),
            // "success" come QuoteGroupResource::sendEmailTableAction(): stessa
            // azione, stesso colore, sia da tabella che da qui.
            Actions\Action::make('send')
                ->label('Invia gruppo')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->form(fn () => QuoteGroupResource::sendEmailFormSchema())
                ->action(fn (array $data) => QuoteGroupResource::sendGroupEmail($this->record, $data)),
            Actions\DeleteAction::make(),
        ];
    }

    // Stesso pattern gia' introdotto in QuoteResource\Pages\EditQuote: "Dati
    // offerta" e "Preventivi" come tab di pari livello invece di un
    // relation manager impilato sotto il form.
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Dati offerta';
    }
}
