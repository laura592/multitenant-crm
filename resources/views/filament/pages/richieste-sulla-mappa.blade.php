{{--
    Le richieste aperte su una mappa, per scegliere una zona e andarci.

    Si riusa il meccanismo di clienti-vicini: stesso `data-nearby-map`,
    stesso <script type="application/json"> per i marker, stesso loader di
    Leaflet in resources/js/app.js. Niente di nuovo da caricare.
--}}
<x-filament-panels::page>
    <div class="space-y-6">

        @php($righe = $this->richieste())
        @php($province = $this->province())
        @php($senza = $this->senzaPosizione())

        <x-filament::section>
            <x-slot name="heading">
                {{ $righe->count() }} richieste aperte{{ $this->provincia ? ' in provincia di '.$this->provincia : '' }}
            </x-slot>
            <x-slot name="description">
                Le più vecchie per prime: i giorni di attesa dicono chi vedere prima, più dello stato.
            </x-slot>

            <div class="flex flex-wrap gap-2" data-tour="richieste-mappa-zone">
                <x-filament::button
                    wire:click="tutteLeZone"
                    size="sm"
                    :color="$this->provincia === null ? 'primary' : 'gray'"
                >Tutte</x-filament::button>

                @foreach ($province as $sigla => $etichetta)
                    <x-filament::button
                        wire:click="$set('provincia', '{{ $sigla }}')"
                        size="sm"
                        :color="$this->provincia === $sigla ? 'primary' : 'gray'"
                    >{{ $etichetta }}</x-filament::button>
                @endforeach
            </div>

            @if ($senza > 0)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Altre {{ $senza }} richieste aperte non compaiono: il cliente non ha un indirizzo da cui
                    ricavare la posizione. Si possono comunque trovare in elenco.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            @php($mapUid = 'richieste-map-'.md5(($this->provincia ?? 'tutte').'-'.$righe->count()))
            @php($mapMarkersId = $mapUid.'-markers')
            @php($marker = $righe->map(fn ($r) => [
                'name' => $r['nome'].' — '.$r['numero'],
                'street' => $r['giorni'].' giorni di attesa'.($r['appuntamento'] ? ' · appuntamento '.$r['appuntamento'] : ''),
                'city' => trim(($r['citta'] ?? '').' ('.($r['provincia'] ?? '—').')'),
                'lat' => $r['lat'],
                'lng' => $r['lng'],
                'color' => $r['colore'],
                'serviceReportUrl' => $r['url'],
            ])->values())

            <div class="space-y-3" x-data x-init="window.dispatchEvent(new CustomEvent('nearby-map:render'))">
                <div
                    id="{{ $mapUid }}"
                    data-nearby-map="1"
                    data-markers-id="{{ $mapMarkersId }}"
                    class="h-[380px] w-full overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-950 sm:h-[560px]"
                ></div>

                <script type="application/json" id="{{ $mapMarkersId }}">@json($marker)</script>

                <p data-nearby-map-status class="text-xs text-gray-500 dark:text-gray-400">
                    Caricamento mappa in corso...
                </p>

                <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <span class="size-3 rounded-full" style="background:#16a34a"></span>
                        Ci vai già: c'è un appuntamento
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-3 rounded-full" style="background:#dc2626"></span>
                        Aspetta da più di 60 giorni
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-3 rounded-full" style="background:#2563eb"></span>
                        Aperta
                    </span>
                    <span>Il verde dice dove sei già diretta: guarda chi gli sta attorno.</span>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">L'elenco, nello stesso ordine</x-slot>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($righe as $r)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2">
                        <span @class([
                            'font-mono text-sm',
                            'text-danger-600 dark:text-danger-400' => $r['giorni'] > 60,
                            'text-gray-500 dark:text-gray-400' => $r['giorni'] <= 60,
                        ])>{{ $r['giorni'] }} gg</span>

                        <a href="{{ $r['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                            {{ $r['nome'] }}
                        </a>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $r['citta'] }} ({{ $r['provincia'] }}) · {{ $r['numero'] }}
                        </span>

                        @if ($r['appuntamento'])
                            <span class="text-xs text-success-600 dark:text-success-400">
                                appuntamento {{ $r['appuntamento'] }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
