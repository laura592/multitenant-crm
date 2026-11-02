<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Dettaglio ore {{ $month }}/{{ $year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        /* Titolo, tabella e nota viaggiano insieme: se non ci stanno in
           fondo alla pagina passano tutti a quella dopo. Senza, la nota
           restava da sola su una pagina quasi vuota. */
        .riepilogo-calce { page-break-inside: avoid; }
        h2.sezione { margin: 18px 0 2px; font-size: 11px; color: #020F30; text-transform: uppercase; letter-spacing: .04em; }
        table.items tr.totale td { border-top: 1.5px solid #020F30; border-bottom: none; font-weight: bold; background: #f0f4fa; }
        .nota-calce { margin-top: 6px; font-size: 8px; color: #6b7280; line-height: 1.5; }

        /* Un dipendente per foglio (Laura, 02/11/2026): prima era un elenco
           unico e il cartellino di uno cominciava a meta' della pagina di un
           altro, impossibile da staccare e consegnare. L'ultimo blocco non
           ha il salto, se no esce una pagina bianca in fondo. */
        .dipendente { page-break-after: always; }
        /* DomPDF non applica :last-of-type: l'ultimo blocco si marca a mano.
           Qui dopo c'e' il riepilogo generale, quindi il salto ci vuole
           sempre tranne quando il riepilogo non c'e'. */
        .dipendente.ultimo { page-break-after: auto; }
        h2.nome { margin: 0 0 6px; font-size: 13px; color: #020F30; }
        .sottotitolo { margin: 0 0 10px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Dettaglio ore</span><br><span class="value">{{ sprintf('%02d', $month) }}/{{ $year }}</span></td>
        </tr>
    </table>

    @php
        // Il riepilogo arriva come elenco: qui serve per nome, per mettere
        // sotto a ogni cartellino i totali di quella persona.
        $totaliPerDipendente = collect($riepilogo ?? [])->keyBy('user');
        $perDipendente = collect($rows)->groupBy('user');
    @endphp

    @foreach($perDipendente as $nome => $righe)
        <div class="dipendente {{ $loop->last && empty($riepilogo) ? 'ultimo' : '' }}">
            <h2 class="nome">{{ $nome }}</h2>
            <p class="sottotitolo">Dettaglio del mese {{ sprintf('%02d', $month) }}/{{ $year }} &mdash; {{ $righe->count() }} {{ $righe->count() === 1 ? 'giornata' : 'giornate' }}</p>

            <table class="items">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th class="numeric">Ore lavorate</th>
                        <th class="numeric">Ordinarie</th>
                        <th class="numeric">Straordinario</th>
                        <th>Trasferta</th>
                        <th>Assenza</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($righe as $row)
                    <tr>
                        <td>{{ $row['date']->format('d/m/Y') }}</td>
                        <td class="numeric">{{ $row['ore_lavorate'] }}</td>
                        <td class="numeric">{{ $row['ordinarie'] }}</td>
                        <td class="numeric">{{ $row['straordinario'] }}</td>
                        <td>{{ $row['trasferta'] ?? '' }}</td>
                        <td>{{ $row['assenza'] ?? '' }}</td>
                    </tr>
                @endforeach

                @if ($totaliPerDipendente->has($nome))
                    @php $t = $totaliPerDipendente[$nome]; @endphp
                    <tr class="totale">
                        <td>Totale del mese</td>
                        <td class="numeric">{{ $righe->sum('ore_lavorate') }}</td>
                        <td class="numeric">{{ $t['ordinarie'] }}</td>
                        <td class="numeric">{{ $t['straordinario'] }}</td>
                        <td>{{ $t['trasferta_giorni'] ? $t['trasferta_giorni'].' gg' : '' }}</td>
                        <td>
                            @if ($t['ferie_giorni']) Ferie {{ $t['ferie_giorni'] }} gg. @endif
                            @if ($t['malattia_giorni']) Malattia {{ $t['malattia_giorni'] }} gg. @endif
                            @if ($t['permessi_ore']) Permesso {{ $t['permessi_ore'] }} h. @endif
                            @if (! empty($t['permessi_104_ore'])) Legge 104 {{ $t['permessi_104_ore'] }} h. @endif
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>

            <p class="nota-calce">
                Lo straordinario del totale comprende anche quello maturato sulla settimana,
                che non si puo' attribuire a un giorno singolo: per questo non coincide con la
                somma della colonna &laquo;Straordinario&raquo; qui sopra.
            </p>
        </div>
    @endforeach

    {{--
        Il quadro d'insieme, su una pagina sua: sono gli stessi numeri
        dell'export "riepilogo", cioe' quelli che vanno in busta paga.
    --}}
    @if (! empty($riepilogo) && count($riepilogo))
        <div class="riepilogo-calce">
        <h2 class="sezione">Riepilogo del mese</h2>

        <table class="items">
            <thead>
                <tr>
                    <th>Dipendente</th>
                    <th class="numeric">Ore ordinarie</th>
                    <th class="numeric">Straordinario</th>
                    <th class="numeric">Giorni ferie</th>
                    <th class="numeric">Giorni malattia</th>
                    <th class="numeric">Ore permesso</th>
                    <th class="numeric">Ore Legge 104</th>
                    <th class="numeric">Giorni trasferta</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($riepilogo as $r)
                <tr>
                    <td>{{ $r['user'] }}</td>
                    <td class="numeric">{{ $r['ordinarie'] }}</td>
                    <td class="numeric">{{ $r['straordinario'] }}</td>
                    <td class="numeric">{{ $r['ferie_giorni'] }}</td>
                    <td class="numeric">{{ $r['malattia_giorni'] }}</td>
                    <td class="numeric">{{ $r['permessi_ore'] }}</td>
                    <td class="numeric">{{ $r['permessi_104_ore'] ?? 0 }}</td>
                    <td class="numeric">{{ $r['trasferta_giorni'] }}</td>
                </tr>
            @endforeach
            @if (! empty($totali))
                <tr class="totale">
                    <td>Totale</td>
                    <td class="numeric">{{ $totali['ordinarie'] }}</td>
                    <td class="numeric">{{ $totali['straordinario'] }}</td>
                    <td class="numeric">{{ $totali['ferie_giorni'] }}</td>
                    <td class="numeric">{{ $totali['malattia_giorni'] }}</td>
                    <td class="numeric">{{ $totali['permessi_ore'] }}</td>
                    <td class="numeric">{{ $totali['permessi_104_ore'] ?? 0 }}</td>
                    <td class="numeric">{{ $totali['trasferta_giorni'] }}</td>
                </tr>
            @endif
            </tbody>
        </table>

        <p class="nota-calce">
            Lo straordinario del riepilogo comprende anche quello maturato sulla settimana,
            che non si puo' attribuire a un giorno singolo: per questo non coincide con la
            somma della colonna &laquo;Straordinario&raquo; dei dettagli qui sopra.
        </p>
        </div>
    @endif

    <div class="footer-note">{{ $tenant?->legal_name ?: $tenant?->name }} &mdash; Generato automaticamente il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
