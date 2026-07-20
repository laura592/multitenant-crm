<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\GoogleCalendarAccount;
use Google\Client;
use Google\Service\Calendar as CalendarService;
use Google\Service\Calendar\Calendar as CalendarResource;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\Events;
use Google\Service\Exception as GoogleServiceException;

/**
 * Wrapper unico su google/apiclient per la sync Appointment <-> Google Calendar
 * (docs/architecture.md §15). Ogni utente collega il proprio account (OAuth
 * opt-in) e la sync avviene sempre su un calendario secondario dedicato,
 * mai su quello primario/personale.
 */
class GoogleCalendarClient
{
    public const DEDICATED_CALENDAR_SUMMARY = 'CRM - Lavoro';

    private Client $client;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->addScope('email');
        // calendars.insert (creazione del calendario secondario dedicato) richiede lo
        // scope completo: gli scope granulari calendar.events/calendarlist non bastano.
        $this->client->addScope(CalendarService::CALENDAR);
    }

    public function authUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in: int, id_token?: string}
     */
    public function exchangeCode(string $code): array
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Google OAuth error: '.$token['error_description'] ?? $token['error']);
        }

        return $token;
    }

    public function emailFromIdToken(array $token): ?string
    {
        if (! isset($token['id_token'])) {
            return null;
        }

        $payload = $this->client->verifyIdToken($token['id_token']);

        return $payload['email'] ?? null;
    }

    public function forAccount(GoogleCalendarAccount $account): self
    {
        $this->client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
        ]);

        if ($this->client->isAccessTokenExpired()) {
            $refreshed = $this->client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            $account->update([
                'access_token' => $refreshed['access_token'],
                'token_expires_at' => now()->addSeconds($refreshed['expires_in'] ?? 3600),
            ]);
        }

        return $this;
    }

    public function revoke(GoogleCalendarAccount $account): void
    {
        try {
            $this->forAccount($account)->client->revokeToken($account->access_token);
        } catch (\Throwable) {
            // Se la revoca lato Google fallisce (token gia' scaduto/invalidato) va bene
            // comunque: l'importante e' che la riga locale venga cancellata dal chiamante.
        }
    }

    public function createDedicatedCalendar(): string
    {
        $calendar = new CalendarResource(['summary' => self::DEDICATED_CALENDAR_SUMMARY]);

        return $this->service()->calendars->insert($calendar)->getId();
    }

    public function upsertEvent(string $calendarId, Appointment $appointment): string
    {
        $event = new Event([
            'summary' => $appointment->title,
            'description' => $appointment->notes,
            'start' => ['dateTime' => $appointment->starts_at->toRfc3339String()],
            'end' => ['dateTime' => $appointment->ends_at->toRfc3339String()],
        ]);

        $events = $this->service()->events;

        $result = $appointment->google_event_id
            ? $events->update($calendarId, $appointment->google_event_id, $event)
            : $events->insert($calendarId, $event);

        return $result->getId();
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        try {
            $this->service()->events->delete($calendarId, $eventId);
        } catch (GoogleServiceException $e) {
            // 404/410 = evento gia' rimosso lato Google, nulla da fare.
            if (! in_array($e->getCode(), [404, 410], true)) {
                throw $e;
            }
        }
    }

    public function listChangedEvents(GoogleCalendarAccount $account): Events
    {
        $events = $this->service()->events;

        if ($account->sync_token) {
            try {
                return $events->listEvents($account->calendar_id, ['syncToken' => $account->sync_token]);
            } catch (GoogleServiceException $e) {
                // 410 = sync token scaduto/non valido, serve una full resync.
                if ($e->getCode() !== 410) {
                    throw $e;
                }

                $account->update(['sync_token' => null]);
            }
        }

        return $events->listEvents($account->calendar_id, [
            'timeMin' => now()->subDay()->toRfc3339String(),
            'singleEvents' => true,
        ]);
    }

    private function service(): CalendarService
    {
        return new CalendarService($this->client);
    }
}
