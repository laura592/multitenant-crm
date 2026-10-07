<x-filament-widgets::widget>
    <x-filament::section heading="Ultimi aggiornamenti da Eureka" description="Quando è girato ogni lavoro con Eureka, com'è finito e cosa ha trovato." collapsible :collapsed="$tuttoBene && ! $inCorso">
        {{-- Il verdetto sta sopra l'elenco, e quando e' tutto a posto
             l'elenco si apre solo se lo si chiede: in cima a questa pagina
             serve sapere se c'e' qualcosa da fare, non sei righe di storia. --}}
        <div style="display:flex;align-items:center;gap:.5rem;padding:2px 0 10px;" class="text-sm">
            <span style="width:.6rem;height:.6rem;border-radius:999px;flex:none;"
                  class="{{ $tuttoBene ? 'bg-success-500' : 'bg-danger-500' }}"></span>
            <span class="font-medium {{ $tuttoBene ? 'text-gray-700 dark:text-gray-200' : 'text-danger-600 dark:text-danger-400' }}">
                {{ $verdetto }}
            </span>
            @if ($inCorso)
                <span class="text-xs text-info-600 dark:text-info-400">— una sincronizzazione è in corso</span>
            @endif
        </div>
        {{-- Tabella semplice con larghezze inline: le classi Tailwind nuove
             non sono nel CSS compilato di Filament. --}}
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;" class="text-sm">
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
                    <tr class="border-t border-gray-100 dark:border-white/5" style="vertical-align: top;">
                        <td style="padding: 8px 16px 8px 0; width: 16rem; min-width: 12rem;">
                            <div class="font-medium text-gray-950 dark:text-white">{{ $lavoro['etichetta'] }}</div>
                            <div class="text-xs text-gray-500">{{ $lavoro['quando'] }}</div>
                        </td>
                        <td style="padding: 8px 16px 8px 0; width: 10rem; min-width: 8rem;" class="font-semibold {{ $colore }}">
                            {{ $etichettaStato }}
                            @if ($ultimo)
                                <div class="text-xs font-normal text-gray-500">{{ $ultimo->avviata_il->timezone(config('app.timezone'))->format('d/m H:i') }}</div>
                            @endif
                        </td>
                        <td style="padding: 8px 0;" class="text-gray-600 dark:text-gray-300">
                            @if ($lavoro['stato'] === 'errore' && $ultimo?->errore)
                                <span class="text-danger-600 dark:text-danger-400">{{ $ultimo->errore }}</span>
                            @elseif ($lavoro['riepilogo'])
                                {{ $lavoro['riepilogo'] }}
                            @elseif (! $ultimo)
                                <span class="text-gray-400">Si vedrà dal primo giro dopo l'aggiornamento.</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
