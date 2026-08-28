<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Resources\QuoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    protected function afterCreate(): void
    {
        $this->prefillFromInformationRequest();

        $this->record->updateTotal();
    }

    /**
     * I "prodotti di interesse" della richiesta informazioni diventano le
     * prime righe del preventivo: sono gli stessi che altrimenti si
     * ridigitano a mano subito dopo. Prezzo dal listino corrente e IVA 22
     * come fa ConfigureMachineAction; restano righe normali, modificabili o
     * cancellabili come le altre.
     */
    private function prefillFromInformationRequest(): void
    {
        $request = $this->record->informationRequest;

        if (! $request) {
            return;
        }

        foreach ($request->products as $product) {
            $this->record->quoteProducts()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $product->getCurrentPrice()?->price ?? 0,
                'discount' => 0,
                'tax' => 22,
            ]);
        }
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
