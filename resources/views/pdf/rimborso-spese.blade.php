<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rimborso spese {{ sprintf('%02d', $month) }}/{{ $year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        /* Un foglio per dipendente: si stacca, si compila e si consegna. */
        .dipendente { page-break-after: always; }
        /* DomPDF non applica :last-of-type: l'ultimo foglio si marca a mano,
           se no esce una pagina bianca in fondo a ogni stampa. */
        .dipendente.ultimo { page-break-after: auto; }

        h2.nome { margin: 0 0 2px; font-size: 13px; color: #020F30; }
        .sottotitolo { margin: 0 0 10px; font-size: 9px; color: #6b7280; }

        /* Le celle da riempire a mano: alte abbastanza per scriverci dentro
           con una penna, non con un carattere da 10px. */
        table.modulo { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.modulo th {
            background: #020F30; color: #fff; font-size: 8px; font-weight: normal;
            text-transform: uppercase; letter-spacing: .03em;
            padding: 5px 4px; text-align: left; border: 0.5px solid #020F30;
        }
        table.modulo td { border: 0.5px solid #9ca3af; padding: 0; height: 22px; font-size: 9px; }
        table.modulo td.scritto { padding: 4px; }
        table.modulo td.numerica { text-align: right; }
        table.modulo tr.totale td { height: 24px; background: #f0f4fa; font-weight: bold; border-color: #020F30; }

        .istruzioni { margin-top: 10px; font-size: 8px; color: #6b7280; line-height: 1.6; }
        .istruzioni strong { color: #374151; }

        table.firme { width: 100%; margin-top: 26px; border-collapse: collapse; }
        table.firme td { width: 50%; padding-top: 26px; font-size: 8px; color: #6b7280; vertical-align: bottom; }
        table.firme .riga { border-top: 0.5px solid #9ca3af; padding-top: 3px; }
    </style>
</head>
<body>
@foreach ($dipendenti as $d)
    <div class="dipendente {{ $loop->last ? 'ultimo' : '' }}">
        <x-pdf-letterhead :tenant="$tenant" />

        <table class="doc-meta">
            <tr>
                <td><span class="label">Rimborso spese</span><br><span class="value">{{ sprintf('%02d', $month) }}/{{ $year }}</span></td>
            </tr>
        </table>

        <h2 class="nome">{{ $d['nome'] }}</h2>
        <p class="sottotitolo">
            @if ($d['giorni']->isNotEmpty())
                {{ $d['giorni']->count() }} {{ $d['giorni']->count() === 1 ? 'giornata di trasferta registrata' : 'giornate di trasferta registrate' }} nel mese. Le righe sono già compilate con data e destinazione: restano da scrivere gli importi.
            @else
                Nessuna trasferta registrata nel mese: il foglio è da compilare a mano.
            @endif
        </p>

        <table class="modulo">
            <thead>
                <tr>
                    <th style="width: 9%">Data</th>
                    <th style="width: 27%">Destinazione / cliente</th>
                    <th style="width: 8%">Km</th>
                    <th style="width: 10%">Pedaggi</th>
                    <th style="width: 10%">Carburante</th>
                    <th style="width: 10%">Vitto</th>
                    <th style="width: 10%">Alloggio</th>
                    <th style="width: 8%">Altro</th>
                    <th style="width: 8%">Totale</th>
                </tr>
            </thead>
            <tbody>
                {{-- Le trasferte che il programma conosce gia'. --}}
                @foreach ($d['giorni'] as $g)
                    <tr>
                        <td class="scritto">{{ $g['data']->format('d/m') }}</td>
                        <td class="scritto">{{ $g['destinazione'] }}</td>
                        <td class="numerica"></td>
                        <td class="numerica"></td>
                        <td class="numerica"></td>
                        <td class="numerica"></td>
                        <td class="numerica"></td>
                        <td class="numerica"></td>
                        <td class="numerica"></td>
                    </tr>
                @endforeach

                {{-- Righe vuote: per le spese di un giorno che la trasferta non
                     ce l'ha segnata, e per chi non ne ha nessuna. --}}
                @for ($i = 0; $i < $d['righe_vuote']; $i++)
                    <tr>
                        <td></td><td></td><td class="numerica"></td><td class="numerica"></td>
                        <td class="numerica"></td><td class="numerica"></td><td class="numerica"></td>
                        <td class="numerica"></td><td class="numerica"></td>
                    </tr>
                @endfor

                <tr class="totale">
                    <td class="scritto" colspan="2">Totale da rimborsare</td>
                    <td class="numerica"></td><td class="numerica"></td><td class="numerica"></td>
                    <td class="numerica"></td><td class="numerica"></td><td class="numerica"></td>
                    <td class="numerica"></td>
                </tr>
            </tbody>
        </table>

        <p class="istruzioni">
            <strong>Come si compila.</strong> Una riga per giornata. I chilometri sono quelli
            effettivamente percorsi con il mezzo proprio: con il furgone aziendale la colonna
            resta vuota e si segna solo il carburante.
            <strong>Le ricevute vanno allegate</strong> — pedaggi, carburante, pasti, pernottamenti:
            senza il documento la voce non si rimborsa. L'indennità di trasferta non va scritta qui:
            è già nel cartellino delle ore e la calcola l'ufficio.
        </p>

        <table class="firme">
            <tr>
                <td><div class="riga">Data e firma del dipendente</div></td>
                <td><div class="riga">Visto per approvazione</div></td>
            </tr>
        </table>

        <div class="footer-note">{{ $tenant?->legal_name ?: $tenant?->name }} &mdash; Modulo generato il {{ now()->format('d/m/Y') }}</div>
    </div>
@endforeach

@include('pdf.partials.page-numbers')
</body>
</html>
