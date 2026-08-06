<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Stesse azioni gia' presenti nel menu di riga della tabella
            // (ProductResource::table()) e nell'header della view.
            ProductResource::confermaCollegamentoGestionaleAction(Actions\Action::make('conferma_collegamento_gestionale')),
            ProductResource::scartaCollegamentoGestionaleAction(Actions\Action::make('scarta_collegamento_gestionale')),
            ProductResource::cercaEurekaAction(Actions\Action::make('cerca_eureka')),
            Actions\DeleteAction::make(),
        ];
    }
}
