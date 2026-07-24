<?php

namespace App\Filament\Resources\InformationRequestResource\Pages;

use App\Filament\Resources\InformationRequestResource;
use App\Mail\NewInformationRequestMail;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Mail;

class CreateInformationRequest extends CreateRecord
{
    protected static string $resource = InformationRequestResource::class;

    // Avvisa i destinatari configurati per le richieste informazioni
    // (pagina Notifiche) solo quando la richiesta nasce da qui, non durante
    // import/seeding: un Observer sul model l'avrebbe sparata anche per ogni
    // riga storica importata da ImportLegacyData.
    protected function afterCreate(): void
    {
        $recipients = $this->record->tenant?->notificationRecipients('information_request') ?? [];

        if (empty($recipients)) {
            return;
        }

        Mail::to($recipients)->send(new NewInformationRequestMail($this->record));
    }
}
