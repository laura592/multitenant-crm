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
            // ConfigureMachineAction resta a colore pieno (primary): e' l'azione
            // principale di questa pagina. Le altre sono di supporto, quindi
            // gray - stesso criterio applicato in QuoteResource::table()/ViewQuote.
            ConfigureMachineAction::make(),
            Actions\Action::make('recalculate')
                ->label('Ricalcola totali')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $this->record->updateTotal();
                    // I campi Totali nel form leggono $record: senza rifillare
                    // il form coi valori freschi da DB resterebbero con i
                    // numeri di prima del ricalcolo fino a un refresh manuale.
                    $this->fillForm();
                })
                ->successNotificationTitle('Totali ricalcolati'),
            Actions\Action::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => QuoteResource::streamPdf($this->record)),
            Actions\Action::make('duplicateAsAlternative')
                ->label('Duplica come alternativa')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Duplicare come preventivo alternativo?')
                ->modalDescription('Crea una copia in bozza per lo stesso cliente, nella stessa offerta (cosi\' potrai inviarli insieme in un\'unica email) - le righe vengono copiate, le note no.')
                ->action(fn () => redirect(QuoteResource::getUrl('edit', ['record' => QuoteResource::duplicateAsAlternative($this->record)]))),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->updateTotal();
    }

    // Senza questo la pagina impilava Dati preventivo -> Totali -> Provvigione
    // -> Righe preventivo uno sotto l'altro: i Totali comparivano prima delle
    // righe che li generano. Con le tab combinate "Righe preventivo" diventa
    // una tab di pari livello invece di un pannello staccato in fondo.
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Dati preventivo';
    }
}
