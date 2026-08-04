<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Mail\CustomerGestionaleReviewMail;
use App\Models\Customer;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Mail;

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
        if ($this->record->gestionale_code === null) {
            return;
        }

        $changed = array_intersect_key(
            Customer::GESTIONALE_TRACKED_FIELDS,
            array_flip(array_keys($this->record->getChanges())),
        );

        if ($changed === []) {
            return;
        }

        $this->record->flagGestionaleReview(array_values($changed));

        $recipients = $this->record->tenant?->notificationRecipients('customer_gestionale') ?? [];

        if ($recipients !== []) {
            Mail::to($recipients)->send(new CustomerGestionaleReviewMail(
                $this->record,
                'Modifica su un cliente già collegato a Eureka: '.implode(', ', $changed).'.',
            ));
        }
    }
}
