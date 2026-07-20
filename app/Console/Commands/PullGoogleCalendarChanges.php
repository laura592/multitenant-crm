<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\GoogleCalendarAccount;
use App\Services\GoogleCalendarClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pull Google -> CRM (docs/architecture.md §15.2): per ogni account connesso,
 * legge gli eventi cambiati sul calendario secondario dedicato dall'ultima
 * esecuzione (syncToken incrementale) e aggiorna/crea/annulla gli Appointment
 * corrispondenti. Schedulato ogni 15 minuti in routes/console.php — niente
 * webhook push per la v1 (richiederebbero un endpoint pubblico verificato).
 */
class PullGoogleCalendarChanges extends Command
{
    protected $signature = 'google-calendar:pull';

    protected $description = 'Importa nel CRM le modifiche fatte su Google Calendar/iOS ai calendari di lavoro collegati';

    public function handle(GoogleCalendarClient $client): int
    {
        foreach (GoogleCalendarAccount::query()->cursor() as $account) {
            $this->pullForAccount($client, $account);
        }

        return self::SUCCESS;
    }

    private function pullForAccount(GoogleCalendarClient $client, GoogleCalendarAccount $account): void
    {
        $client->forAccount($account);

        $events = $client->listChangedEvents($account);

        foreach ($events->getItems() as $event) {
            if ($event->getStatus() === 'cancelled') {
                Appointment::withoutEvents(function () use ($event) {
                    Appointment::where('google_event_id', $event->getId())
                        ->update(['status' => Appointment::STATUS_ANNULLATO]);
                });

                continue;
            }

            $start = $event->getStart()?->getDateTime();
            $end = $event->getEnd()?->getDateTime();

            if (! $start || ! $end) {
                // Evento "tutto il giorno" (solo date, senza orario): non e' un
                // appuntamento nel nostro modello, si ignora.
                continue;
            }

            Appointment::withoutEvents(function () use ($account, $event, $start, $end) {
                Appointment::updateOrCreate(
                    ['google_event_id' => $event->getId()],
                    [
                        'tenant_id' => $account->tenant_id,
                        'technician_id' => $account->user_id,
                        'title' => $event->getSummary() ?: '(senza titolo)',
                        'notes' => $event->getDescription(),
                        'starts_at' => Carbon::parse($start),
                        'ends_at' => Carbon::parse($end),
                        'google_synced_at' => now(),
                    ]
                );
            });
        }

        $account->update(['sync_token' => $events->getNextSyncToken() ?? $account->sync_token]);
    }
}
