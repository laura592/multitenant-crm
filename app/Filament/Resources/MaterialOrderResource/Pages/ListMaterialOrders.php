<?php

namespace App\Filament\Resources\MaterialOrderResource\Pages;

use App\Filament\Resources\MaterialOrderResource;
use App\Models\MaterialOrder;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListMaterialOrders extends ListRecords
{
    protected static string $resource = MaterialOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Niente form intermedio: l'ordine (con numero gia' assegnato)
            // esiste in DB dal primo istante, cosi' non c'e' mai uno stato
            // "non salvato" da perdere con un refresh.
            Actions\Action::make('create')
                ->label('Nuovo ordine materiali')
                ->icon('heroicon-o-plus')
                ->action(function () {
                    // Il numero viene assegnato da MaterialOrder::booted() se non
                    // passato esplicitamente.
                    $order = MaterialOrder::create(['tenant_id' => Filament::getTenant()?->id]);

                    return redirect(MaterialOrderResource::getUrl('edit', ['record' => $order]));
                }),
        ];
    }
}
