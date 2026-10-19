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
     * L'appuntamento e' uno solo: cambiarlo o svuotarlo dal form cancella
     * quello di prima, note comprese ("passa lui allo stand", "chiedere di
     * Marcello"). Prima di salvare lo travasiamo nel diario, cosi' la
     * storia della richiesta resta leggibile (19/10/2026).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        InformationRequestResource::archiviaAppuntamento($this->record, $data['appointment_at'] ?? null);

        return $data;
    }
}
