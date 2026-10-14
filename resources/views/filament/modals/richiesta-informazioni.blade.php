{{-- La richiesta da cui nasce il preventivo, in finestra invece che stesa
     nella pagina: dal preventivo serve sapere cosa aveva chiesto il cliente,
     ma sono quattro righe che rubavano mezzo schermo e il testo usciva
     tagliato a 300 caratteri (Laura, 14/10/2026). --}}
@php
    $etichette = \App\Filament\Resources\InformationRequestResource::statusLabels();
@endphp
<div class="space-y-4 text-sm">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
        <div>
            <div class="text-gray-500 dark:text-gray-400">Richiesta</div>
            <div class="font-semibold">{{ $richiesta->number }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Arrivata il</div>
            <div class="font-semibold">{{ $richiesta->created_at?->format('d/m/Y') }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Stato</div>
            <div class="font-semibold">{{ $etichette[$richiesta->status] ?? $richiesta->status }}</div>
        </div>
        @if($richiesta->appointment_at)
            <div>
                <div class="text-gray-500 dark:text-gray-400">In agenda</div>
                <div class="font-semibold">{{ $richiesta->appointment_at->format('d/m/Y H:i') }}</div>
            </div>
        @endif
    </div>

    @if(filled($richiesta->appointment_notes))
        <p class="text-gray-600 dark:text-gray-300">{{ $richiesta->appointment_notes }}</p>
    @endif

    <div>
        <div class="mb-1 text-gray-500 dark:text-gray-400">Cosa chiedeva</div>
        {{-- Per intero: e' il motivo per cui si apre questa finestra. --}}
        <div class="whitespace-pre-line rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">{{ trim((string) $richiesta->request_details) ?: '—' }}</div>
    </div>

    <a href="{{ \App\Filament\Resources\InformationRequestResource::getUrl('edit', ['record' => $richiesta]) }}"
       class="inline-flex items-center gap-1 font-semibold text-primary-600 hover:underline dark:text-primary-400">
        <x-heroicon-o-arrow-top-right-on-square class="h-5 w-5" />
        Apri la richiesta
    </a>
</div>
