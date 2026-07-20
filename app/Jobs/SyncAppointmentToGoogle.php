<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\GoogleCalendarAccount;
use App\Services\GoogleCalendarClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Push CRM -> Google per un singolo Appointment (docs/architecture.md §15.2).
 * $appointmentId null = l'appuntamento e' stato cancellato, va rimosso l'evento.
 */
class SyncAppointmentToGoogle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $appointmentId,
        public readonly string $technicianId,
        public readonly ?string $googleEventId,
    ) {}

    public function handle(GoogleCalendarClient $client): void
    {
        $account = GoogleCalendarAccount::where('user_id', $this->technicianId)->first();

        if (! $account) {
            return;
        }

        $client->forAccount($account);

        if ($this->appointmentId === null) {
            if ($this->googleEventId) {
                $client->deleteEvent($account->calendar_id, $this->googleEventId);
            }

            return;
        }

        $appointment = Appointment::find($this->appointmentId);

        if (! $appointment) {
            return;
        }

        $eventId = $client->upsertEvent($account->calendar_id, $appointment);

        $appointment->google_event_id = $eventId;
        $appointment->google_synced_at = now();
        $appointment->saveQuietly();
    }
}
