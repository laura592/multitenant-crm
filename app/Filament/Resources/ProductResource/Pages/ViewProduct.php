<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Stesse azioni gia' presenti nel menu di riga della tabella
            // (ProductResource::table()): da quando il click riga apre la
            // view invece dell'edit, da qui non si passa piu' dal menu
            // azioni della tabella per usarle.
            ProductResource::confermaCollegamentoGestionaleAction(Actions\Action::make('conferma_collegamento_gestionale')),
            ProductResource::scartaCollegamentoGestionaleAction(Actions\Action::make('scarta_collegamento_gestionale')),
            ProductResource::cercaEurekaAction(Actions\Action::make('cerca_eureka')),
            Actions\EditAction::make(),
        ];
    }
}
