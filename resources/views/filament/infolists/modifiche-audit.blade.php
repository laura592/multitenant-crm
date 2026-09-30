@php
    /** @var \App\Models\AuditLog $record */
    $record = $getRecord();
    $righe = \App\Support\Audit\Modifiche::righe($record);
    $soloUnValore = in_array($record->event, ['created', 'deleted', 'restored'], true);
    $colonnaValore = $record->event === 'deleted' ? 'prima' : 'dopo';
    $intestazione = match ($record->event) {
        'created' => 'Valore inserito',
        'deleted' => 'Valore che aveva',
        default => 'Dopo',
    };
@endphp

<div class="fi-in-entry-wrp">
    @if ($righe === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Nessun dettaglio registrato per questa voce.
        </p>
    @else
        <div class="overflow-hidden rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Campo</th>
                        @unless ($soloUnValore)
                            <th class="px-3 py-2">Prima</th>
                        @endunless
                        <th class="px-3 py-2">{{ $intestazione }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($righe as $riga)
                        <tr class="align-top">
                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                {{ $riga['campo'] }}
                            </td>
                            @unless ($soloUnValore)
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400 line-through decoration-gray-300 dark:decoration-gray-600">
                                    {{ $riga['prima'] }}
                                </td>
                            @endunless
                            <td class="px-3 py-2 text-gray-950 dark:text-white">
                                {{ $riga[$colonnaValore] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
