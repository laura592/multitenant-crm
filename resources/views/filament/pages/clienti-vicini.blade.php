<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Rileva la tua posizione per vedere i clienti più vicini, ordinati per distanza (primi 25 risultati).
                </p>
                @if($latitude !== null && $longitude !== null)
                    <p class="text-sm font-medium mt-1">Posizione rilevata: {{ number_format($latitude, 5) }}, {{ number_format($longitude, 5) }}</p>
                @endif
            </div>
            <button type="button"
                x-on:click="
                    navigator.geolocation.getCurrentPosition(
                        (pos) => {
                            $wire.set('latitude', pos.coords.latitude);
                            $wire.set('longitude', pos.coords.longitude);
                        },
                        (err) => alert('Impossibile ottenere la posizione: ' + err.message)
                    )
                "
                class="fi-btn fi-btn-color-primary inline-flex items-center justify-center gap-1 rounded-lg border px-4 py-2 text-sm font-medium shadow-sm bg-primary-600 text-white hover:bg-primary-500 border-transparent">
                Trova la mia posizione
            </button>
        </div>
    </x-filament::section>

    @if($latitude !== null && $longitude !== null)
        <x-filament::section>
            {{-- overflow-x-auto: senza, su mobile la tabella (4 colonne, indirizzi
                 lunghi) sfonda la larghezza e fa scrollare l'intera pagina invece
                 del solo blocco tabella. --}}
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-sm">
                    <thead>
                        <tr class="text-left border-b">
                            <th class="py-2 pr-4">Cliente</th>
                            <th class="py-2 pr-4">Indirizzo</th>
                            <th class="py-2 pr-4">Distanza</th>
                            <th class="py-2 pr-4">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->getNearbyCustomers() as $row)
                            <tr class="border-b">
                                <td class="py-2 pr-4 font-medium">{{ $row['customer']->full_name }}</td>
                                <td class="py-2 pr-4 text-gray-500">{{ $row['customer']->street }}, {{ $row['customer']->city }}</td>
                                <td class="py-2 pr-4">{{ number_format($row['distance'], 1) }} km</td>
                                <td class="py-2 pr-4">
                                    <div class="flex gap-3">
                                        <a href="{{ $this->serviceReportUrlFor($row['customer']) }}" class="text-primary-600 hover:underline">Apri rapportino</a>
                                        <a href="{{ $this->mapsUrlFor($row['customer']) }}" target="_blank" class="text-gray-500 hover:underline">Apri in Maps</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-gray-500">Nessun cliente con posizione GPS salvata in questo tenant.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
