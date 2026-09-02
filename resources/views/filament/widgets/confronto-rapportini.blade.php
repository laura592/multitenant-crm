{{-- Confronto affiancato fra il rapportino del tecnico e la scheda importata
     da Eureka: senza vedere il contenuto non si puo' decidere se documentano
     lo stesso intervento. --}}
@php
    $campi = [
        'Data' => fn ($r) => $r->intervention_date?->format('d/m/Y'),
        'Tecnico' => fn ($r) => $r->technician?->name,
        'Macchina' => fn ($r) => $r->machineUnit?->display_name,
        'Matricola' => fn ($r) => $r->machineUnit?->serial_number ?: $r->machine_serial_number,
        'Problema' => fn ($r) => $r->problem_description,
        'Lavoro svolto' => fn ($r) => $r->work_performed,
        'Note' => fn ($r) => $r->notes,
        'N. scheda Eureka' => fn ($r) => $r->gestionale_number,
    ];
@endphp

<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-4">
        <div class="font-semibold">{{ $nostro->number }} — compilato dal tecnico</div>
        <div class="font-semibold">{{ $importato->number }} — importato da Eureka</div>
    </div>

    @foreach ($campi as $etichetta => $valore)
        @php($a = $valore($nostro))
        @php($b = $valore($importato))
        @continue(blank($a) && blank($b))
        <div>
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etichetta }}</div>
            <div class="grid grid-cols-2 gap-4">
                {{-- Fondo ambra dove i due valori differiscono: e' li' che serve l'occhio. --}}
                <div @class(['rounded p-2', 'bg-amber-50 dark:bg-amber-950/40' => filled($a) && filled($b) && $a !== $b])>
                    {{ filled($a) ? $a : '—' }}
                </div>
                <div @class(['rounded p-2', 'bg-amber-50 dark:bg-amber-950/40' => filled($a) && filled($b) && $a !== $b])>
                    {{ filled($b) ? $b : '—' }}
                </div>
            </div>
        </div>
    @endforeach

    <div>
        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Materiali usati</div>
        <div class="grid grid-cols-2 gap-4">
            @foreach ([$nostro, $importato] as $r)
                <div class="rounded p-2">
                    @forelse ($r->materialsUsed as $riga)
                        <div>{{ $riga->material?->code ?? '—' }}
                            <span class="text-gray-500">× {{ rtrim(rtrim(number_format((float) $riga->quantity, 2, ',', '.'), '0'), ',') }}</span>
                        </div>
                    @empty
                        <div class="text-gray-500">nessun materiale</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>
</div>
