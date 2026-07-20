<x-filament-panels::page>
    <x-filament::section>
        @php($account = $this->getAccount())

        @if ($account)
            <div class="flex flex-col gap-y-2">
                <x-filament::badge color="success" icon="heroicon-o-check-circle">
                    Connesso
                </x-filament::badge>
                <p>
                    Collegato come <strong>{{ $account->google_account_email }}</strong>
                    dal {{ $account->connected_at->translatedFormat('d/m/Y') }}.
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    I tuoi appuntamenti vengono sincronizzati con il calendario secondario
                    "{{ \App\Services\GoogleCalendarClient::DEDICATED_CALENDAR_SUMMARY }}" nel tuo account
                    Google — il tuo calendario personale non viene mai toccato. Su iPhone/iPad, aggiungi
                    lo stesso account Google in Impostazioni → Calendario → Account per vederlo anche
                    nell'app Calendario di Apple.
                </p>
            </div>
        @else
            <x-filament::badge color="gray" icon="heroicon-o-x-circle" class="mb-2">
                Non connesso
            </x-filament::badge>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Collega il tuo account Google per vedere i tuoi appuntamenti anche sul tuo calendario
                personale (Google Calendar e, se lo aggiungi su iPhone/iPad, anche Calendario di Apple).
                Verra' creato un calendario secondario dedicato "{{ \App\Services\GoogleCalendarClient::DEDICATED_CALENDAR_SUMMARY }}",
                separato dal tuo calendario personale.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
