{{-- Stampa dello scaduto clienti: e' la lista con cui si telefona, quindi
     carta prima che estetica — righe fitte, importi allineati a destra e una
     colonna vuota a fine riga per segnare a penna l'esito della chiamata. --}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        .sottotitolo { font-size: 8.5pt; color: #555; margin-bottom: 10px; }
        .riepilogo { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .riepilogo td { border: 1px solid #d4d4d4; padding: 6px 8px; }
        .riepilogo .etichetta { font-size: 7.5pt; color: #666; text-transform: uppercase; }
        .riepilogo .valore { font-size: 12pt; font-weight: bold; }
        table.elenco { width: 100%; border-collapse: collapse; }
        table.elenco thead th {
            border-bottom: 1.5px solid #111; padding: 5px 6px; font-size: 8pt;
            text-transform: uppercase; text-align: left; color: #333;
        }
        table.elenco td { border-bottom: 0.5px solid #ddd; padding: 5px 6px; vertical-align: top; }
        tr.pari td { background: #f7f7f7; }
        .destra { text-align: right; }
        .centro { text-align: center; }
        .cliente { font-weight: bold; }
        .nota { font-size: 7.5pt; color: #666; }
        .rosso { color: #b91c1c; font-weight: bold; }
        .ambra { color: #b45309; }
        .esito { width: 22%; }
        .pie { margin-top: 14px; font-size: 7.5pt; color: #777; }
    </style>
</head>
<body>

<h1>Scaduto clienti</h1>
<div class="sottotitolo">
    {{ $tenant?->name }} — situazione al {{ $data }}
    @if($ricerca) · filtrato per “{{ $ricerca }}” @endif
    · importi al netto delle note di credito
</div>

<table class="riepilogo">
    <tr>
        <td width="33%">
            <div class="etichetta">Clienti da chiamare</div>
            <div class="valore">{{ count($righe) }}</div>
        </td>
        <td width="33%">
            <div class="etichetta">Totale scaduto</div>
            <div class="valore">€ {{ number_format($totale, 2, ',', '.') }}</div>
        </td>
        <td>
            <div class="etichetta">Attesa più lunga</div>
            <div class="valore">{{ $attesaMassima !== null ? $attesaMassima.' giorni' : '—' }}</div>
        </td>
    </tr>
</table>

<table class="elenco">
    <thead>
        <tr>
            <th width="4%">#</th>
            <th>Cliente</th>
            <th width="12%" class="centro">Ferma da</th>
            <th width="16%" class="destra">Scaduto</th>
            <th class="esito">Esito telefonata</th>
        </tr>
    </thead>
    <tbody>
        @foreach($righe as $i => $riga)
            <tr @class(['pari' => $i % 2 === 1])>
                <td>{{ $i + 1 }}</td>
                <td>
                    <span class="cliente">{{ $riga['cliente'] }}</span>
                    <div class="nota">
                        {{ $riga['fatture'] }} {{ $riga['fatture'] == 1 ? 'fattura' : 'fatture' }}
                        @if($riga['crediti'] > 0)
                            · € {{ number_format($riga['lordo'], 2, ',', '.') }} meno € {{ number_format($riga['crediti'], 2, ',', '.') }} di note di credito
                        @endif
                    </div>
                </td>
                <td class="centro @if($riga['giorni'] !== null && $riga['giorni'] > 180) rosso @elseif($riga['giorni'] !== null && $riga['giorni'] > 60) ambra @endif">
                    {{ $riga['giorni'] !== null ? $riga['giorni'].' gg' : '—' }}
                    @if($riga['piu_vecchia'])
                        <div class="nota">dal {{ $riga['piu_vecchia'] }}</div>
                    @endif
                </td>
                <td class="destra cliente">€ {{ number_format($riga['scaduto'], 2, ',', '.') }}</td>
                <td></td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="pie">
    Stampato il {{ $data }} · l'elenco segue l'ordine e la ricerca impostati a schermo ·
    i dati arrivano da Eureka (eureka:import-partite-aperte)
</div>

</body>
</html>
