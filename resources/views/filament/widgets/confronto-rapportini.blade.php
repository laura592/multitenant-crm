{{-- Confronto fra il rapportino del tecnico e la scheda importata da Eureka.

     Si confrontano SOLO i campi che dicono qualcosa sull'intervento. Il
     tecnico no: l'import assegna a tutte le schede lo stesso utente di
     ripiego, perche' l'operatore di Eureka e' un codice che non corrisponde
     a un utente del CRM. Segnalarlo come differenza sarebbe rumore su ogni
     singola riga. --}}
@php
    $decidono = [
        'Matricola' => fn ($r) => $r->machineUnit?->serial_number ?: $r->machine_serial_number,
        'Macchina' => fn ($r) => $r->machineUnit?->display_name,
        'Problema' => fn ($r) => $r->problem_description,
        'Lavoro svolto' => fn ($r) => $r->work_performed,
        'Note' => fn ($r) => $r->notes,
    ];

    $materiali = fn ($r) => $r->materialsUsed
        ->map(fn ($riga) => trim(($riga->material?->code ?? '—')
            .' × '.rtrim(rtrim(number_format((float) $riga->quantity, 2, ',', '.'), '0'), ',')))
        ->all();
@endphp

<div class="space-y-5 text-sm">
    <p class="text-gray-500 dark:text-gray-400">
        Stesso cliente, stesso giorno ({{ $nostro->intervention_date?->format('d/m/Y') }}).
        In ambra i campi che non coincidono.
    </p>

    <div class="grid grid-cols-2 gap-4 font-semibold">
        <div>{{ $nostro->number }}<div class="text-xs font-normal text-gray-500">compilato dal tecnico</div></div>
        <div>{{ $importato->number }}<div class="text-xs font-normal text-gray-500">
            {{-- Niente @if attaccato alla parola precedente: Blade non
                 riconosce una direttiva quando la @ segue un carattere di
                 parola ("Eureka@if" resta testo), ma il suo @endif si', e
                 la vista non compilava piu'. --}}
            importato da Eureka{{ $importato->gestionale_number ? ', scheda n. '.$importato->gestionale_number : '' }}
        </div></div>
    </div>

    @foreach ($decidono as $etichetta => $valore)
        @php($a = $valore($nostro))
        @php($b = $valore($importato))
        @continue(blank($a) && blank($b))
        @php($diverso = filled($a) && filled($b) && $a !== $b)
        <div>
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etichetta }}</div>
            <div class="grid grid-cols-2 gap-4">
                <div @class(['rounded p-2', 'bg-amber-50 dark:bg-amber-950/40' => $diverso])>{{ filled($a) ? $a : '—' }}</div>
                <div @class(['rounded p-2', 'bg-amber-50 dark:bg-amber-950/40' => $diverso])>{{ filled($b) ? $b : '—' }}</div>
            </div>
        </div>
    @endforeach

    @php($ma = $materiali($nostro))
    @php($mb = $materiali($importato))
    <div>
        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Materiali usati</div>
        <div class="grid grid-cols-2 gap-4">
            @foreach ([$ma, $mb] as $lista)
                <div class="rounded p-2">
                    @forelse ($lista as $riga)
                        {{-- In ambra solo i materiali che l'altro non ha: quelli
                             in comune sono l'indizio che e' lo stesso intervento. --}}
                        <div @class(['rounded px-1', 'bg-amber-50 dark:bg-amber-950/40' => ! in_array($riga, $loop->parent->index === 0 ? $mb : $ma, true)])>{{ $riga }}</div>
                    @empty
                        <div class="text-gray-500">nessun materiale</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>

    <p class="border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
        Il tecnico non viene confrontato: le schede importate risultano tutte assegnate
        allo stesso utente di ripiego, quindi il dato corretto resta quello del CRM
        ({{ $nostro->technician?->name ?? '—' }}). Confermando, il rapportino del tecnico
        rimane e riceve il collegamento a Eureka.
    </p>
</div>
