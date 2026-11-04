{{--
    L'agenda: un mese alla volta, con in cima quello che e' gia' scaduto.

    La fascia degli scaduti non e' un di piu': su questi dati gli
    appuntamenti mancati sono di settembre e ottobre, cioe' in mesi che
    nessuno riaprira'. Un calendario che li mostra solo nella loro casella
    li nasconde (04/11/2026).

    Griglia a mano e non una libreria: sette colonne di celle non valgono
    un caricamento da CDN, e il progetto non ha gia' un calendario da
    riusare.
--}}
<x-filament-panels::page>
    <div class="space-y-6">

        @php($scaduti = $this->appuntamentiScaduti())

        @if ($scaduti->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">
                    Appuntamenti passati e mai chiusi ({{ $scaduti->count() }})
                </x-slot>
                <x-slot name="description">
                    La richiesta è ancora aperta e il giorno è passato. Sono quelli che fanno perdere i clienti.
                </x-slot>

                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($scaduti as $v)
                        <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2">
                            <span class="font-mono text-sm text-danger-600 dark:text-danger-400">
                                {{ \Illuminate\Support\Carbon::parse($v['giorno'])->format('d/m/Y') }}
                                @if ($v['ora']) · {{ $v['ora'] }} @endif
                            </span>
                            @if ($v['url'])
                                <a href="{{ $v['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $v['chi'] }}</a>
                            @else
                                <span class="text-sm font-medium">{{ $v['chi'] }}</span>
                            @endif
                            @if ($v['etichetta'])
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $v['etichetta'] }}</span>
                            @endif
                            @if ($v['note'])
                                <span class="w-full text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($v['note'], 140) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">{{ $this->titoloMese() }}</x-slot>

            <x-slot name="headerEnd">
                <div class="flex items-center gap-2" data-tour="agenda-mesi">
                    <x-filament::button wire:click="mesePrecedente" color="gray" size="sm" icon="heroicon-m-chevron-left">
                        Mese prima
                    </x-filament::button>
                    <x-filament::button wire:click="oggi" color="gray" size="sm">Oggi</x-filament::button>
                    <x-filament::button wire:click="meseSuccessivo" color="gray" size="sm" icon="heroicon-m-chevron-right" icon-position="after">
                        Mese dopo
                    </x-filament::button>
                </div>
            </x-slot>

            <div class="grid grid-cols-7 gap-px rounded-xl bg-gray-200 p-px dark:bg-gray-800">
                @foreach (['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'] as $nome)
                    <div class="bg-gray-50 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        {{ $nome }}
                    </div>
                @endforeach

                @foreach ($this->giorniDellaGriglia() as $giorno)
                    @php($voci = $this->vociDelGiorno($giorno))
                    @php($fuoriMese = $giorno->month !== $this->primoDelMese()->month)
                    @php($oggi = $giorno->isToday())

                    <div @class([
                        'min-h-28 bg-white p-2 dark:bg-gray-950',
                        'opacity-50' => $fuoriMese,
                        'ring-2 ring-inset ring-primary-500' => $oggi,
                    ])>
                        <div @class([
                            'mb-1 text-xs font-semibold',
                            'text-primary-600 dark:text-primary-400' => $oggi,
                            'text-gray-400 dark:text-gray-600' => ! $oggi,
                        ])>{{ $giorno->day }}</div>

                        <div class="space-y-1">
                            @foreach ($voci as $v)
                                {{-- Gli attributi condizionali si calcolano qui e non
                                     con @if dentro il tag: inline confondono il
                                     compilatore Blade e la pagina esce in 500. --}}
                                @php($classi = $v['scaduto'] ? 'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-300' : ($v['tipo'] === 'appuntamento' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-900 dark:text-gray-300'))

                                <a
                                    href="{{ $v['url'] ?? '#' }}"
                                    title="{{ $v['note'] ?? '' }}"
                                    class="block rounded-md px-1.5 py-1 text-xs leading-tight hover:underline {{ $classi }}"
                                >
                                    <span class="font-mono font-semibold">{{ $v['ora'] }}</span>
                                    {{ \Illuminate\Support\Str::limit($v['chi'], 26) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-slot name="footer">
                <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <span class="size-3 rounded bg-primary-100 dark:bg-primary-900"></span> Appuntamento su una richiesta
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-3 rounded bg-gray-200 dark:bg-gray-800"></span> Lavoro in programmazione
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-3 rounded bg-danger-100 dark:bg-danger-900"></span> Passato e non chiuso
                    </span>
                </div>
            </x-slot>
        </x-filament::section>
    </div>
</x-filament-panels::page>
