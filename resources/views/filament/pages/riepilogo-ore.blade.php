<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section>
        <table class="fi-ta-table w-full text-sm">
            <thead>
                <tr class="text-left border-b">
                    <th class="py-2 pr-4">Dipendente</th>
                    <th class="py-2 pr-4">Ore ordinarie</th>
                    <th class="py-2 pr-4">Straordinario</th>
                    <th class="py-2 pr-4">Giorni ferie</th>
                    <th class="py-2 pr-4">Ore permesso</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->getRows() as $row)
                    <tr class="border-b">
                        <td class="py-2 pr-4">{{ $row['user'] }}</td>
                        <td class="py-2 pr-4">{{ $row['ordinarie'] }}</td>
                        <td class="py-2 pr-4">{{ $row['straordinario'] }}</td>
                        <td class="py-2 pr-4">{{ $row['ferie_giorni'] }}</td>
                        <td class="py-2 pr-4">{{ $row['permessi_ore'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-gray-500">Nessun dipendente in questo tenant.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
