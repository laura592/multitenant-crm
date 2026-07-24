<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Resources\QuoteResource;
use App\Models\QuoteGroup;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Solo "Invia" resta a colore pieno (success): e' l'azione che fa
            // avanzare davvero il preventivo verso il cliente. Le altre sono
            // di supporto/secondarie, quindi gray - stesso criterio gia'
            // applicato alle azioni di riga in QuoteResource::table().
            Actions\Action::make('convertToOffer')
                ->label('Crea offerta globale')
                ->icon('heroicon-o-rectangle-stack')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Creare un\'offerta globale da questo preventivo?')
                ->modalDescription('Il preventivo verra\' collegato a un gruppo offerta. Potrai poi aggiungere altre soluzioni usando "Duplica come alternativa".')
                ->action(function () {
                    $group = $this->record->quoteGroup ?: QuoteGroup::create([
                        'tenant_id' => $this->record->tenant_id,
                        'customer_id' => $this->record->customer_id,
                        'status' => 'bozza',
                    ]);

                    if (! $this->record->quote_group_id) {
                        $this->record->update(['quote_group_id' => $group->id]);
                    }

                    Notification::make()
                        ->title("Preventivo collegato all'offerta {$group->number}")
                        ->success()
                        ->send();

                    $this->redirect(QuoteResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('duplicateAsAlternative')
                ->label('Duplica come alternativa')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Duplicare come preventivo alternativo?')
                ->modalDescription('Crea una copia in bozza per lo stesso cliente, nella stessa offerta (cosi\' potrai inviarli insieme in un\'unica email) - le righe vengono copiate, le note no.')
                ->action(fn () => $this->redirect(QuoteResource::getUrl('edit', ['record' => QuoteResource::duplicateAsAlternative($this->record)]))),
            Actions\Action::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => QuoteResource::streamPdf($this->record)),
            Actions\Action::make('send')
                ->label('Invia')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->form(fn () => QuoteResource::sendEmailFormSchema())
                ->action(fn (array $data) => QuoteResource::sendQuoteEmail($this->record, $data)),
            Actions\EditAction::make()
                ->color('gray'),
        ];
    }
}
