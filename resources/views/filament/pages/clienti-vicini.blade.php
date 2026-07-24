<x-filament-panels::page>
    @php
        $markers = $this->nearbyCustomerMarkers();
        $nearbyCustomers = $this->getNearbyCustomers();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    x-on:click="
                        navigator.geolocation.getCurrentPosition(
                            (pos) => {
                                $wire.set('latitude', pos.coords.latitude);
                                $wire.set('longitude', pos.coords.longitude);
                            },
                            (err) => alert('Impossibile ottenere la posizione: ' + err.message)
                        )
                    "
                    class="fi-btn fi-btn-color-primary inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-transparent bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500"
                >
                    Trova la mia posizione
                </button>

                <div class="flex shrink-0 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-950">
                    @if($latitude !== null && $longitude !== null)
                        <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                        <span class="font-semibold text-emerald-700 dark:text-emerald-300">Posizione attiva</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ number_format($latitude, 5) }}, {{ number_format($longitude, 5) }}</span>
                    @else
                        <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
                        <span class="font-semibold text-amber-700 dark:text-amber-300">Posizione non attiva</span>
                    @endif
                </div>

                <div class="h-8 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></div>

                <label class="flex shrink-0 items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-gray-500 dark:text-gray-400">Raggio (km)</span>
                    <input
                        type="number"
                        min="1"
                        max="100"
                        step="1"
                        wire:model.live="maxDistanceKm"
                        class="fi-input block w-20 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                    >
                </label>

                <div class="flex shrink-0 gap-1.5">
                    @foreach ([1, 2, 3] as $preset)
                        <button
                            type="button"
                            wire:click="$set('maxDistanceKm', {{ $preset }})"
                            class="rounded-lg border px-2.5 py-2 text-xs font-semibold transition {{ (int) $maxDistanceKm === $preset ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:bg-gray-900' }}"
                        >
                            {{ $preset }} km
                        </button>
                    @endforeach
                </div>

                @if($latitude !== null && $longitude !== null)
                    <div class="h-8 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></div>

                    <div class="flex shrink-0 items-center gap-3 text-xs">
                        <span class="text-gray-500 dark:text-gray-400">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ number_format($maxDistanceKm, 0) }} km</span>
                            raggio
                        </span>
                        <span class="text-gray-500 dark:text-gray-400">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $nearbyCustomers->count() }}</span>
                            clienti mostrati
                        </span>
                    </div>
                @endif

                <p class="ml-auto shrink-0 text-right text-xs text-gray-400 dark:text-gray-500">
                    Predefinito {{ number_format($this->defaultMaxDistanceKm(), 0) }} km &middot; max {{ $this->maxResults() }} risultati
                </p>
            </div>
        </x-filament::section>

        @if($latitude !== null && $longitude !== null)
            <div class="grid gap-6 lg:grid-cols-[1.3fr_0.7fr]">
                <x-filament::section>
                    <div class="mb-4 flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Mappa clienti</h2>
                            @if($this->isUsingDistanceFallback())
                                <p class="mt-1 text-sm text-amber-700 dark:text-amber-200">
                                    Nessun cliente entro {{ number_format($maxDistanceKm, 0) }} km: mostro i {{ \App\Filament\Pages\ClientiVicini::FALLBACK_RESULTS }} piu vicini.
                                </p>
                            @else
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Marker ordinati per distanza rispetto alla tua posizione.
                                </p>
                            @endif
                        </div>
                        <div class="rounded-full border border-gray-200 px-3 py-1 text-xs font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">
                            {{ count($markers) }} risultati
                        </div>
                    </div>

                    @php
                        $mapUid = 'nearby-map-' . md5(($latitude ?? '') . '-' . ($longitude ?? '') . '-' . $maxDistanceKm . '-' . count($markers));
                        $mapMarkersId = $mapUid . '-markers';
                    @endphp

                    <div class="space-y-3" x-data x-init="window.dispatchEvent(new CustomEvent('nearby-map:render'))">
                        <div
                            id="{{ $mapUid }}"
                            data-nearby-map="1"
                            data-user-lat="{{ $latitude }}"
                            data-user-lng="{{ $longitude }}"
                            data-markers-id="{{ $mapMarkersId }}"
                            style="height: 520px;"
                            class="w-full overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-950"
                        ></div>

                        <script type="application/json" id="{{ $mapMarkersId }}">@json($markers)</script>

                        <p data-nearby-map-status class="text-xs text-gray-500 dark:text-gray-400">
                            Caricamento mappa in corso...
                        </p>

                        <iframe
                            data-nearby-map-fallback
                            title="Mappa clienti vicini (fallback)"
                            src="{{ $this->mapEmbedUrl() }}"
                            style="height: 520px;"
                            class="w-full overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700"
                            loading="lazy"
                        ></iframe>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Clienti ordinati per distanza</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Apri subito il rapportino o la navigazione.</p>
                    </div>

                    @if($this->isUsingDistanceFallback())
                        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                            Nessun cliente nel raggio scelto: elenco riempito con i piu vicini disponibili.
                        </div>
                    @endif

                    <div class="max-h-[620px] space-y-3 overflow-y-auto pr-1">
                        @forelse($nearbyCustomers as $index => $row)
                            <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">#{{ $index + 1 }}</p>
                                        <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $row['customer']->full_name }}</h3>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ $row['customer']->city }}
                                        </p>
                                    </div>
                                    <div class="inline-flex rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                                        {{ number_format($row['distance'], 1) }} km
                                    </div>
                                </div>

                                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                                    {{ trim(collect([
                                        $row['customer']->street,
                                        trim(($row['customer']->postal_code ?? '') . ' ' . ($row['customer']->city ?? '')),
                                        $row['customer']->province,
                                    ])->filter()->implode(', '), ', ') ?: 'Indirizzo non disponibile' }}
                                </p>

                                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                                    <a href="{{ $this->serviceReportUrlFor($row['customer']) }}" class="inline-flex items-center rounded-lg bg-primary-600 px-3 py-1.5 font-medium text-white hover:bg-primary-500">
                                        Apri rapportino
                                    </a>
                                    <a href="{{ $this->mapsUrlFor($row['customer']) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">
                                        Apri in Maps
                                    </a>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-400">
                                Nessun cliente entro {{ number_format($maxDistanceKm, 0) }} km con coordinate disponibili in questo tenant.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
        @else
            <x-filament::section>
                <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center dark:border-gray-700 dark:bg-gray-900/60">
                    <div class="mx-auto max-w-xl space-y-3">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Attiva la posizione per iniziare</h2>
                        <p class="text-sm leading-6 text-gray-500 dark:text-gray-400">
                            Premi "Trova la mia posizione", scegli il raggio e visualizzerai subito mappa e clienti ordinati.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
