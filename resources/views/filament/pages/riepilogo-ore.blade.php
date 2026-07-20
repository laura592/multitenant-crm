<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $rows = $this->getRows();
        $totals = $this->getTotals($rows);
        $fmt = fn ($n) => number_format((float) $n, 2, ',', '.');
    @endphp

    <x-filament::section>
        {{-- overflow-x-auto: senza, la tabella (5 colonne) sfonda la larghezza
             della section su schermi stretti e fa scrollare l'intera pagina
             in orizzontale invece del solo blocco tabella. --}}
        <div class="overflow-x-auto">
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
                    @forelse($rows as $row)
                        <tr class="border-b">
                            <td class="py-2 pr-4">{{ $row['user'] }}</td>
                            <td class="py-2 pr-4">{{ $fmt($row['ordinarie']) }}</td>
                            <td class="py-2 pr-4">{{ $fmt($row['straordinario']) }}</td>
                            <td class="py-2 pr-4">{{ $fmt($row['ferie_giorni']) }}</td>
                            <td class="py-2 pr-4">{{ $fmt($row['permessi_ore']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-gray-500">Nessun dipendente in questo tenant.</td></tr>
                    @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot>
                        <tr class="font-semibold border-t">
                            <td class="py-2 pr-4">Totale</td>
                            <td class="py-2 pr-4">{{ $fmt($totals['ordinarie']) }}</td>
                            <td class="py-2 pr-4">{{ $fmt($totals['straordinario']) }}</td>
                            <td class="py-2 pr-4">{{ $fmt($totals['ferie_giorni']) }}</td>
                            <td class="py-2 pr-4">{{ $fmt($totals['permessi_ore']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
