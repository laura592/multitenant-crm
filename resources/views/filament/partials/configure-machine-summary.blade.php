<table class="fi-ta-table w-full text-sm">
    <tbody>
        @foreach($rows as $i => $row)
            <tr class="border-b border-gray-100 dark:border-white/5">
                <td class="py-1.5 pr-4 {{ $i === 0 ? 'font-semibold' : '' }}">{{ $row['label'] }}</td>
                <td class="py-1.5 text-right whitespace-nowrap">{{ number_format($row['price'], 2, ',', '.') }} €</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="border-t-2 border-gray-300 dark:border-white/10">
            <td class="py-2 pr-4">Subtotale</td>
            <td class="py-2 text-right whitespace-nowrap">{{ number_format($subtotal, 2, ',', '.') }} €</td>
        </tr>
        @if($discount > 0)
            <tr>
                <td class="py-1.5 pr-4">Sconto configurazione ({{ number_format($discount, 2, ',', '.') }}%)</td>
                <td class="py-1.5 text-right whitespace-nowrap">-{{ number_format($subtotal * $discount / 100, 2, ',', '.') }} €</td>
            </tr>
        @endif
        <tr class="font-semibold">
            <td class="py-2 pr-4">Totale (imponibile)</td>
            <td class="py-2 text-right whitespace-nowrap">{{ number_format($total, 2, ',', '.') }} €</td>
        </tr>
    </tfoot>
</table>
