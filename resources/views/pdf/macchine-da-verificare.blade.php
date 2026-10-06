<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Macchine da verificare</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        {{-- Foglio da stampare e girare con la penna in mano: le righe sono
             alte e c'e' una casella vuota per la risposta, perche' la lista
             serve a decidere macchina per macchina dove sta davvero. --}}
        .blocco { background: #f0f4fa; border-left: 3px solid #020F30; padding: 5px 8px; font-size: 10px; font-weight: bold; color: #020F30; margin: 14px 0 5px; }
        .blocco .quante { float: right; font-weight: normal; color: #4b5563; }
        table.items td { font-size: 9px; padding-top: 6px; padding-bottom: 6px; }
        .col-matricola { width: 19%; }
        .col-presso { width: 24%; }
        .col-ritiro { width: 13%; }
        .col-ultimo { width: 24%; }
        .col-risposta { width: 20%; }
        .muted { color: #6b7280; font-size: 8.5px; }
        .casella { border-bottom: 1px solid #9ca3af; display: block; height: 11px; }
        .nota { background: #f9fafb; border: 1px solid #e5e7eb; padding: 7px 9px; margin-top: 8px; color: #374151; font-size: 9px; }
        .vuoto { padding: 14px; background: #f9fafb; border: 1px solid #e5e7eb; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Macchine da verificare</span><span class="value">ritiri non ancora confermati</span></td>
            <td class="to-right"><span class="label">Al</span><span class="value">{{ $oggi->format('d/m/Y') }}</span>
                <span class="label" style="margin-left:10px">Totale</span><span class="value">{{ $totale }}</span></td>
        </tr>
    </table>

    <div class="nota">
        Sono le macchine che un rapportino dà per <strong>ritirate</strong> e che nel gestionale risultano ancora
        presso il cliente. Per ognuna serve sapere dov'è davvero: in magazzino, ancora dal cliente, oppure
        consegnata a qualcun altro — in quest'ultimo caso scrivere a chi. Le risposte si riportano poi nel
        gestionale, in <em>Revisione sincronizzazione</em>.
    </div>

    @foreach ($gruppi as $titolo => $righe)
        <div class="blocco">{{ $titolo }}<span class="quante">{{ count($righe) }} macchine</span></div>

        @if (empty($righe))
            <p class="vuoto">Nessuna macchina in questo periodo.</p>
        @else
            <table class="items">
                <thead>
                    <tr>
                        <th class="col-matricola">Matricola</th>
                        <th class="col-presso">Nel gestionale presso</th>
                        <th class="col-ritiro">Ritirata il</th>
                        <th class="col-ultimo">Ultimo intervento</th>
                        <th class="col-risposta">Dov'è davvero</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($righe as $r)
                    <tr>
                        <td>
                            <strong>{{ $r['matricola'] }}</strong>
                            @if($r['modello'])<div class="muted">{{ $r['modello'] }}</div>@endif
                        </td>
                        <td>
                            {{ $r['cliente'] ?: '—' }}
                            @if($r['citta'])<div class="muted">{{ $r['citta'] }}</div>@endif
                        </td>
                        <td>
                            {{ $r['ritirata'] }}
                            @if($r['rapportino'])<div class="muted">{{ $r['rapportino'] }}</div>@endif
                        </td>
                        <td>
                            {{ $r['ultimo'] ?: '—' }}
                            @if($r['ultimo_presso'])<div class="muted">{{ $r['ultimo_presso'] }}</div>@endif
                        </td>
                        <td><span class="casella"></span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @include('pdf.partials.page-numbers')
</body>
</html>
