{{-- Riepilogo dell'ultimo passo di "Invia": due colonne, etichetta e valore.
     Stesso impianto di configure-machine-summary, che e' la tabellina che si
     legge in un colpo d'occhio prima di confermare. --}}
<table class="fi-ta-table w-full text-sm">
    <tbody>
        @foreach($righe as $riga)
            <tr class="border-b border-gray-100 dark:border-white/5">
                <td class="py-1.5 pr-4 align-top whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $riga['label'] }}</td>
                <td @class([
                    'py-1.5 align-top break-words',
                    'font-semibold' => $riga['strong'] ?? false,
                ])>{{ $riga['value'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if($avviso)
    <p @class([
        'mt-3 rounded-lg px-3 py-2 text-sm',
        'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $avvisoGrave,
        'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400' => ! $avvisoGrave,
    ])>{{ $avviso }}</p>
@endif
