<x-filament-widgets::widget>
    <x-filament::section heading="Ultimi aggiornamenti da Eureka" description="Quando è girato ogni lavoro con Eureka, com'è finito e cosa ha trovato.">
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($lavori as $lavoro)
                @php
                    [$colore, $etichettaStato] = match ($lavoro['stato']) {
                        'ok' => ['text-success-600 dark:text-success-400', 'OK'],
                        'in_corso' => ['text-info-600 dark:text-info-400', 'In corso'],
                        'errore' => ['text-danger-600 dark:text-danger-400', 'Fallito'],
                        'fermo' => ['text-warning-600 dark:text-warning-400', 'Non gira da troppo'],
                        default => ['text-gray-500', 'Mai registrato'],
                    };
                    $ultimo = $lavoro['ultimo'];
                @endphp
                <div class="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:gap-4">
                    <div class="sm:w-64 shrink-0">
                        <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $lavoro['etichetta'] }}</div>
                        <div class="text-xs text-gray-500">{{ $lavoro['quando'] }}</div>
                    </div>
                    <div class="sm:w-40 shrink-0 text-sm font-semibold {{ $colore }}">
                        {{ $etichettaStato }}
                        @if ($ultimo)
                            <div class="text-xs font-normal text-gray-500">{{ $ultimo->avviata_il->timezone(config('app.timezone'))->format('d/m H:i') }}</div>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1 text-sm text-gray-600 dark:text-gray-300">
                        @if ($lavoro['stato'] === 'errore' && $ultimo?->errore)
                            <span class="text-danger-600 dark:text-danger-400">{{ $ultimo->errore }}</span>
                        @elseif ($lavoro['riepilogo'])
                            {{ $lavoro['riepilogo'] }}
                        @elseif (! $ultimo)
                            <span class="text-gray-400">Registrato da quando c'è questa striscia (22/09/2026).</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
