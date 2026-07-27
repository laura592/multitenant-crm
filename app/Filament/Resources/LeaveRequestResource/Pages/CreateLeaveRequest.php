<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use App\Mail\NewLeaveRequestMail;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    /**
     * Il campo user_id e' disabled() lato UI per chi non e' responsabile/
     * amministrazione, ma disabled() non impedisce di forgiare un user_id
     * diverso nel payload della richiesta: qui lo si riscrive lato server.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (! LeaveRequestResource::isResponsabile($user) && ! $user->hasRole('amministrazione')) {
            $data['user_id'] = $user->id;
        }

        return $data;
    }

    // Avvisa i destinatari configurati per le ferie/permessi (pagina
    // Notifiche, stessa lista usata in CC su approvazione/rifiuto) solo
    // quando la richiesta nasce da qui, non durante import/seeding: vedi
    // CreateInformationRequest::afterCreate() per lo stesso pattern.
    protected function afterCreate(): void
    {
        $recipients = $this->record->tenant?->notificationRecipients('leave_request') ?? [];

        if (empty($recipients)) {
            return;
        }

        // La richiesta e' gia' salvata a questo punto: un intoppo SMTP
        // (credenziali, timeout) non deve far tornare un 500 al dipendente
        // e fargli credere che la richiesta non sia stata registrata.
        try {
            Mail::to($recipients)->send(new NewLeaveRequestMail($this->record));
        } catch (\Throwable $e) {
            Log::error('Invio notifica nuova richiesta ferie/permesso fallito', [
                'leave_request_id' => $this->record->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
