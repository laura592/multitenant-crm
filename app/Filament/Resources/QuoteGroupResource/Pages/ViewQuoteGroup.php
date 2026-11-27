<?php

namespace App\Filament\Resources\QuoteGroupResource\Pages;

use App\Filament\Resources\QuoteGroupResource;
use App\Models\QuoteGroup;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;

/**
 * La scheda dell'offerta, in sola lettura (27/11/2026).
 *
 * Nasce dalla pagina "Da sollecitare": prima di telefonare a un cliente si
 * apre la trattativa per sapere che cosa gli abbiamo mandato e quando lo
 * abbiamo sentito. Del preventivo singolo si apriva la visualizzazione, che
 * quelle due cose le mostra; dell'offerta si finiva nel form di modifica,
 * che le nasconde.
 *
 * Le azioni sono le stesse che l'offerta ha gia' nell'elenco (PDF, Invia) e
 * la modifica: qui non si inventa niente di nuovo.
 */
class ViewQuoteGroup extends ViewRecord
{
    protected static string $resource = QuoteGroupResource::class;

    /**
     * Le alternative e gli invii si caricano qui, ordinati.
     *
     * Non e' un'ottimizzazione, e' il posto giusto: le RepeatableEntry
     * dell'infolist stampano le righe leggendo la relazione dal record, non
     * una query loro. Un ->state() sulla entry cambia solo l'elenco che
     * vedono le closure (il link al preventivo, "che cosa propone") e lascia
     * i valori stampati presi dalla relazione: due elenchi in ordine diverso
     * e il link di una riga finisce su un'altra. Successo davvero, il
     * 27/11/2026, su OFF-2026-0009: "Errore" comparve accanto a due invii
     * riusciti.
     *
     * Precaricare le righe serve poi a "che cosa propone", che le legge una
     * per alternativa: su sei alternative sarebbero sei query in piu'.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->load([
            'quotes' => fn ($query) => $query->orderBy('number')->with('baseQuoteProducts.product.category'),
            'emails.user',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('previewPdfs')
                ->label('PDF')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                // Il record arriva iniettato invece che da $this->record:
                // la proprieta' e' dichiarata Model|int|string, mentre
                // l'azione di pagina ce l'ha gia' tipizzata (Filament la
                // passa con ->record()).
                ->action(fn (QuoteGroup $record) => QuoteGroupResource::streamGroupPdfsZip($record)),
            // Solo "Invia" resta a colore pieno (success): e' l'azione che
            // fa avanzare l'offerta verso il cliente. Le altre sono di
            // supporto, quindi gray — stesso criterio delle azioni di riga
            // in QuoteGroupResource::table().
            Actions\Action::make('send')
                ->label('Invia')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->form(fn () => QuoteGroupResource::sendEmailFormSchema())
                ->action(fn (QuoteGroup $record, array $data) => QuoteGroupResource::sendGroupEmail($record, $data)),
            Actions\EditAction::make()
                ->color('gray'),
        ];
    }

    /**
     * Niente relation manager su questa pagina.
     *
     * Le alternative e gli invii li mostra gia' l'infolist, e vederli due
     * volte sulla stessa scheda e' il difetto che si era appena corretto
     * sul preventivo. I due relation manager, poi, servono a MODIFICARE
     * ("Aggiungi soluzione", "Sgancia dalla offerta"): il loro posto e' la
     * pagina di modifica, dove restano.
     *
     * @return array<class-string|RelationGroup|RelationManagerConfiguration>
     */
    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
