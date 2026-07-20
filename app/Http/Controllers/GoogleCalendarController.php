<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarAccount;
use App\Services\GoogleCalendarClient;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleCalendarController extends Controller
{
    public function connect(GoogleCalendarClient $client): RedirectResponse
    {
        return redirect()->away($client->authUrl());
    }

    public function callback(Request $request, GoogleCalendarClient $client): RedirectResponse
    {
        $user = $request->user();
        $redirectTo = Filament::getUrl();

        if ($request->has('error')) {
            Notification::make()->title('Connessione a Google Calendar annullata')->warning()->send();

            return redirect($redirectTo);
        }

        $token = $client->exchangeCode($request->query('code'));

        if (! isset($token['refresh_token']) && ! $user->googleCalendarAccount) {
            // Google non restituisce un refresh_token se l'utente ha gia' concesso il
            // consenso in passato senza revocarlo: senza, non possiamo rinnovare
            // l'accesso in background, quindi chiediamo di rifare il collegamento
            // dopo aver revocato l'accesso dell'app dal proprio account Google.
            Notification::make()
                ->title('Google non ha fornito un token di rinnovo')
                ->body('Revoca l\'accesso app da myaccount.google.com/permissions e riprova.')
                ->danger()
                ->send();

            return redirect($redirectTo);
        }

        $email = $client->emailFromIdToken($token);

        $account = $user->googleCalendarAccount ?? new GoogleCalendarAccount(['user_id' => $user->id]);
        $account->tenant_id = $user->tenant_id;
        $account->google_account_email = $email;
        $account->access_token = $token['access_token'];
        $account->refresh_token = $token['refresh_token'] ?? $account->refresh_token;
        $account->token_expires_at = now()->addSeconds($token['expires_in'] ?? 3600);
        $account->connected_at = now();
        $account->save();

        if (! $account->calendar_id) {
            $account->update([
                'calendar_id' => $client->forAccount($account)->createDedicatedCalendar(),
            ]);
        }

        Notification::make()->title('Google Calendar collegato con successo')->success()->send();

        return redirect($redirectTo);
    }
}
