<?php

namespace App\Filament\Pages;

use App\Services\GoogleCalendarClient;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Impostazioni personali di collegamento a Google Calendar (docs/architecture.md
 * §15). Ogni utente sceglie se collegare il proprio account — non e' una
 * risorsa aziendale, quindi niente gate Shield: chiunque sia autenticato gestisce
 * solo il proprio collegamento.
 */
class GoogleCalendarSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Impostazioni';

    protected static ?string $navigationLabel = 'Google Calendar';

    protected static ?string $title = 'Collegamento Google Calendar';

    protected static string $view = 'filament.pages.google-calendar-settings';

    public function getAccount(): ?\App\Models\GoogleCalendarAccount
    {
        return auth()->user()->googleCalendarAccount;
    }

    protected function getHeaderActions(): array
    {
        if ($this->getAccount()) {
            return [
                Action::make('disconnect')
                    ->label('Scollega')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (GoogleCalendarClient $client) {
                        $account = $this->getAccount();
                        $client->revoke($account);
                        $account->delete();

                        Notification::make()->title('Google Calendar scollegato')->success()->send();
                    }),
            ];
        }

        return [
            Action::make('connect')
                ->label('Collega Google Calendar')
                ->icon('heroicon-o-link')
                ->color('primary')
                ->url(fn () => route('google-calendar.connect')),
        ];
    }
}
