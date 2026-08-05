<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // Solo le modifiche fatte a mano da qui, non un Observer sul model:
    // un Observer scatterebbe anche per gli update() in bulk di
    // ImportLegacyData/EnrichCustomerContactsFromGestionale, che toccano
    // proprio email/telefono/indirizzo (vedi stesso ragionamento in
    // CreateInformationRequest::afterCreate()).
    protected function afterSave(): void
    {
        $this->record->notifyGestionaleReviewIfLinked(array_keys($this->record->getChanges()));
    }
}
