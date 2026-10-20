<?php

namespace App\Filament\Resources\InformationRequestResource\Pages;

use App\Filament\Actions\ContattaCliente;
use App\Filament\Resources\InformationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInformationRequest extends EditRecord
{
    protected static string $resource = InformationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ContattaCliente::perPagina($this->record->customer),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Dopo il salvataggio le colonne appointment_at/appointment_notes
     * tornano a dire qual e' il PROSSIMO appuntamento dell'elenco: le
     * leggono l'elenco delle richieste, i colori e la stampa.
     */
    protected function afterSave(): void
    {
        $this->record->refresh()->sincronizzaProssimoAppuntamento();
    }
}
