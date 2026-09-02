{{-- Confronto a colonne fra il rapportino del tecnico e la scheda importata.

     Una riga per campo, con l'etichetta a sinistra: cosi' l'occhio scorre in
     orizzontale e il confronto e' immediato. La versione precedente impilava
     i campi e costringeva a saltare su e giu' per accoppiarli.

     Due cose non compaiono, perche' sarebbero rumore su ogni riga:
     - il tecnico, che l'import assegna sempre allo stesso utente di ripiego
       (l'operatore di Eureka e' un codice senza corrispondenza nel CRM);
     - la boilerplate "Numero documento Eureka: NNN", unico contenuto delle
       note importate, gia' conservata in gestionale_number. --}}
@php
    use App\Support\Gestionale\ConfrontoRapportini;

    $campi = [
        'Matricola' => fn ($r) => ConfrontoRapportini::matricola($r) === '' ? '' : ($r->machineUnit?->serial_number ?: $r->machine_serial_number),
        'Macchina' => fn ($r) => $r->machineUnit?->display_name,
        'Problema' => fn ($r) => ConfrontoRapportini::testoUtile($r->problem_description),
        'Lavoro svolto' => fn ($r) => ConfrontoRapportini::testoUtile($r->work_performed),
        'Note' => fn ($r) => ConfrontoRapportini::testoUtile($r->notes),
    ];

    $materiali = fn ($r) => $r->materialsUsed
        ->mapWithKeys(fn ($riga) => [
            ($riga->material?->code ?? '—') => rtrim(rtrim(number_format((float) $riga->quantity, 2, ',', '.'), '0'), ','),
        ])->all();

    $ma = $materiali($nostro);
    $mb = $materiali($importato);
    $comuni = array_intersect_key($ma, $mb);

    // Si contano i campi IN CONTRASTO, non quelli uguali: un campo pieno da
    // una parte e vuoto dall'altra non e' una discordanza, e dire "0 su 1
    // coincidono" scoraggia senza informare. Quello che serve sapere prima di
    // decidere e' se qualcosa stona.
    $contrasti = [];
    foreach ($campi as $etichetta => $valore) {
        $a = trim((string) $valore($nostro));
        $b = trim((string) $valore($importato));
        if ($a !== '' && $b !== '' && $a !== $b) {
            $contrasti[] = mb_strtolower($etichetta);
        }
    }
@endphp

<div class="space-y-4 text-sm">
    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
        <div class="font-medium">
            {{ \App\Support\DisplayName::titleCase($nostro->customer?->company_name) }}
            — {{ $nostro->intervention_date?->format('d/m/Y') }}
        </div>
        <div class="text-gray-600 dark:text-gray-400">
            {{-- I materiali in comune sono l'indizio piu' forte che sia lo
                 stesso intervento: vanno letti per primi. --}}
            @if ($comuni)
                <span class="text-green-700 dark:text-green-400">
                    {{ count($comuni) }} {{ count($comuni) === 1 ? 'materiale in comune' : 'materiali in comune' }}
                </span>
            @elseif ($ma || $mb)
                <span class="text-amber-700 dark:text-amber-400">nessun materiale in comune</span>
            @else
                <span>nessun materiale su entrambi</span>
            @endif
            @if ($contrasti)
                · <span class="text-amber-700 dark:text-amber-400">
                    {{ count($contrasti) === 1 ? 'in contrasto:' : 'campi in contrasto:' }}
                    {{ implode(', ', $contrasti) }}
                </span>
            @else
                · nessun campo in contrasto
            @endif
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full table-fixed">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
                <tr>
                    <th class="w-32 px-3 py-2 font-medium">Campo</th>
                    <th class="px-3 py-2 font-medium">
                        {{ $nostro->number }}
                        <span class="block font-normal normal-case">compilato dal tecnico</span>
                    </th>
                    <th class="px-3 py-2 font-medium">
                        {{ $importato->number }}
                        <span class="block font-normal normal-case">
                            da Eureka{{ $importato->gestionale_number ? ', scheda n. '.$importato->gestionale_number : '' }}
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 align-top dark:divide-gray-700">
                @foreach ($campi as $etichetta => $valore)
                    @php($a = trim((string) $valore($nostro)))
                    @php($b = trim((string) $valore($importato)))
                    @continue($a === '' && $b === '')
                    @php($diverso = $a !== '' && $b !== '' && $a !== $b)
                    {{-- Si tinge solo dove entrambi hanno un valore e differiscono. --}}
                    <tr @class(['bg-amber-50/60 dark:bg-amber-950/30' => $diverso])>
                        <td class="px-3 py-2 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $etichetta }}
                            @if ($a !== '' && $b !== '' && ! $diverso)
                                <span class="text-green-600 dark:text-green-400" title="coincidono">&check;</span>
                            @endif
                        </td>
                        <td class="whitespace-pre-line px-3 py-2">{{ $a !== '' ? $a : '—' }}</td>
                        <td class="whitespace-pre-line px-3 py-2">{{ $b !== '' ? $b : '—' }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td class="px-3 py-2 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Materiali</td>
                    @foreach ([[$ma, $mb], [$mb, $ma]] as [$mia, $altrui])
                        <td class="px-3 py-2">
                            @forelse ($mia as $codice => $qta)
                                <div @class([
                                    'rounded px-1',
                                    'bg-green-50 dark:bg-green-950/30' => array_key_exists($codice, $altrui),
                                    'bg-amber-50 dark:bg-amber-950/30' => ! array_key_exists($codice, $altrui),
                                ])>
                                    {{ $codice }} <span class="text-gray-500">× {{ $qta }}</span>
                                </div>
                            @empty
                                <span class="text-gray-500">nessun materiale</span>
                            @endforelse
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Confermando, resta il rapportino del tecnico ({{ $nostro->number }}) e riceve il
        collegamento a Eureka. I testi si sommano: quello importato viene aggiunto in coda,
        marcato «Da Eureka», senza sostituire il vostro. Lo stato passa a
        «In gestionale»: da quel momento il documento va corretto su Eureka.
        {{-- Adottare la macchina cambia il rapportino piu' di un testo in coda:
             va detto prima di premere, non scoperto dopo. --}}
        @if (! $nostro->machine_unit_id && $importato->machine_unit_id)
            <span class="text-green-700 dark:text-green-400">
                Questo rapportino non ha una macchina e riceverà quella della scheda
                @if ($importato->machineUnit?->serial_number)
                    (matricola {{ $importato->machineUnit->serial_number }}).
                @else
                    importata.
                @endif
            </span>
        @endif
    </p>
</div>
