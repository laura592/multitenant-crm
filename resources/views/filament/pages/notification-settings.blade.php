<x-filament-panels::page>
    <form wire:submit="save">
        <x-filament::section
            icon="heroicon-o-bell-alert"
            heading="Destinatari notifiche"
            description="Configura destinatari diversi per ogni tipo di evento, per questa azienda."
        >
            {{ $this->form }}

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <div class="flex items-start gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <x-filament::icon
                        icon="heroicon-o-inbox-arrow-down"
                        class="mt-0.5 h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500"
                    />
                    <div class="text-sm">
                        <p class="font-medium text-gray-950 dark:text-white">Nuova richiesta informazioni</p>
                        <p class="text-gray-500 dark:text-gray-400">Avviso quando un cliente invia una richiesta dal sito.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <x-filament::icon
                        icon="heroicon-o-calendar-days"
                        class="mt-0.5 h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500"
                    />
                    <div class="text-sm">
                        <p class="font-medium text-gray-950 dark:text-white">Ferie e permessi</p>
                        <p class="text-gray-500 dark:text-gray-400">In copia quando una richiesta viene approvata o rifiutata.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <x-filament::icon
                        icon="heroicon-o-document-text"
                        class="mt-0.5 h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500"
                    />
                    <div class="text-sm">
                        <p class="font-medium text-gray-950 dark:text-white">Preventivi</p>
                        <p class="text-gray-500 dark:text-gray-400">In copia all'invio dei preventivi ai clienti.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <x-filament::icon
                        icon="heroicon-o-rectangle-stack"
                        class="mt-0.5 h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500"
                    />
                    <div class="text-sm">
                        <p class="font-medium text-gray-950 dark:text-white">Offerte globali</p>
                        <p class="text-gray-500 dark:text-gray-400">In copia all'invio delle offerte con piu soluzioni.</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-between gap-3 border-t border-gray-950/5 pt-4 dark:border-white/10">
                @php
                    $groups = [
                        'notify_information_request_emails' => count($this->data['notify_information_request_emails'] ?? []),
                        'notify_leave_request_emails' => count($this->data['notify_leave_request_emails'] ?? []),
                        'notify_quote_emails' => count($this->data['notify_quote_emails'] ?? []),
                        'notify_quote_group_emails' => count($this->data['notify_quote_group_emails'] ?? []),
                    ];

                    $configuredGroups = collect($groups)->filter(fn ($groupCount) => $groupCount > 0)->count();
                @endphp

                @if ($configuredGroups > 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $configuredGroups }} {{ $configuredGroups === 1 ? 'gruppo configurato' : 'gruppi configurati' }} su 4
                    </p>
                @else
                    <p class="flex items-center gap-1.5 text-sm text-warning-600 dark:text-warning-400">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                        Nessun destinatario configurato: queste notifiche non verranno inviate a nessuno.
                    </p>
                @endif

                <x-filament::button type="submit" icon="heroicon-o-check">
                    Salva
                </x-filament::button>
            </div>
        </x-filament::section>
    </form>
</x-filament-panels::page>
