<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Actions\ConfigureMachineAction;
use App\Filament\Concerns\RedirectsCancelToView;
use App\Filament\Resources\QuoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditQuote extends EditRecord
{
    use RedirectsCancelToView;

    protected static string $resource = QuoteResource::class;

    /**
     * Le righe del preventivo vivono in un RelationManager, che e' un
     * componente Livewire a se': quando cambia una riga, questa pagina non
     * se ne accorge da sola e i Totali restano quelli di prima.
     */
    #[On('totaliPreventivoAggiornati')]
    public function aggiornaTotali(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }

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
                ->extraAttributes(['data-tour' => 'quotes-recalculate'])
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
                ->extraAttributes(['data-tour' => 'quotes-pdf'])
                ->url(fn () => route('quotes.pdf', $this->record))
                ->openUrlInNewTab(),
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
