<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Lettura del {{ $lettura->data->format('d/m/Y') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        table.items td.numero { font-size: 13px; font-weight: bold; color: #020F30; }
        table.items td small { color: #6b7280; }

        .operazioni { margin-top: 14px; }
        .operazioni span { display: inline-block; background: #eef1f5; border: 1px solid #d1d5db; padding: 3px 8px; margin: 0 4px 4px 0; font-size: 9.5px; }
        .operazioni .mancante { background: #fff; color: #9ca3af; }

        {{-- page-break-inside non e' sempre onorato da dompdf sui blocchi
             lunghi, ma su un riquadro foto di altezza nota funziona: e'
             quello che evita la didascalia in fondo a una pagina e la foto
             nella successiva. --}}
        .foto { margin-top: 14px; page-break-inside: avoid; }
        .foto .didascalia { background: #020F30; color: #fff; padding: 4px 10px; font-size: 9.5px; font-weight: bold; }
        .foto .didascalia .letto { float: right; font-weight: normal; }
        .foto .cornice { border: 1px solid #d1d5db; border-top: none; padding: 8px; text-align: center; }
        .foto img { display: block; margin: 0 auto; }

        .senza-foto { margin-top: 14px; padding: 10px 12px; background: #fffbeb; border: 1px solid #fcd34d; border-left: 3px solid #f59e0b; font-size: 10px; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Lettura casetta</span><br><span class="value">{{ $lettura->machineUnit?->serial_number ?: '—' }}</span></td>
            <td class="to-right"><span class="label">Data della lettura</span><br><span class="value">{{ $lettura->data->format('d/m/Y') }}</span></td>
        </tr>
    </table>

    <div class="section-title">Dove</div>
    <div class="info-box" style="margin-bottom: 14px;">
        <div class="customer-name">{{ $lettura->customer?->company_name ?: 'Cliente non indicato' }}</div>
        <table>
            @php
                // La casetta non ha un indirizzo suo: sta dove sta il Comune
                // che la ospita.
                $comune = $lettura->customer;
                $indirizzo = trim(collect([
                    $comune?->street,
                    trim(trim(($comune?->postal_code ?? '').' '.($comune?->city ?? '')).' '.($comune?->province ? '('.$comune->province.')' : '')),
                ])->filter()->implode(', '), ' ,');
            @endphp
            @if($indirizzo)
                <tr><td class="label">Sede:</td><td>{{ $indirizzo }}</td></tr>
            @endif
            @if($lettura->rilevataDa)
                <tr><td class="label">Rilevata da:</td><td>{{ \App\Support\DisplayName::titleCase($lettura->rilevataDa->name) }}</td></tr>
            @endif
        </table>
    </div>

    {{-- Il contatore progressivo e, sotto, quanto ha fatto dalla lettura
         precedente: e' la differenza che si fattura, non il totale. Senza
         una lettura precedente la differenza non esiste e la casella resta
         vuota -- vedi la nota in testa ad App\Models\LetturaCasetta. --}}
    <div class="section-title">I contatori</div>
    <table class="items">
        <thead>
            <tr>
                <th>Contatore</th>
                <th class="numeric">Lettura di oggi</th>
                <th class="numeric">Nel periodo</th>
            </tr>
        </thead>
        <tbody>
            @php
                $righe = [
                    ['Acqua erogata', $lettura->contatore_acqua, $lettura->erogato(), $lettura->unitaBreve()],
                    ['Incasso', $lettura->incasso_totale, $lettura->incassoPeriodo(), '€'],
                    ['Venduto', $lettura->valore_vendite, $lettura->vendutoPeriodo(), '€'],
                ];
                $precedente = $lettura->precedente();
                $euro = fn ($v, $u) => $v === null ? null : number_format((float) $v, 2, ',', '.').' '.$u;
            @endphp
            @foreach($righe as [$etichetta, $totale, $periodo, $unita])
                <tr>
                    <td>{{ $etichetta }}</td>
                    <td class="numeric numero">{{ $euro($totale, $unita) ?: '—' }}</td>
                    <td class="numeric numero">{{ $euro($periodo, $unita) ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="row-table" style="margin-top: 6px;">
        <tr>
            <td style="font-size: 9px; color: #6b7280;">
                @if($precedente)
                    «Nel periodo» è la differenza con la lettura del {{ $precedente->data->format('d/m/Y') }}.
                @else
                    Prima lettura di questa casetta: non c'è un periodo da calcolare.
                @endif
            </td>
        </tr>
    </table>

    <div class="section-title" style="margin-top: 14px;">Che cosa è stato fatto</div>
    <div class="operazioni">
        @foreach(\App\Models\LetturaCasetta::OPERAZIONI as $colonna => $etichetta)
            <span class="{{ $lettura->{$colonna} ? '' : 'mancante' }}">{{ $lettura->{$colonna} ? '✓' : '—' }} {{ $etichetta }}</span>
        @endforeach
    </div>

    @if($lettura->note)
        <div class="notes-box">
            <h2>Note</h2>
            <p>{{ $lettura->note }}</p>
        </div>
    @endif

    @if($foto === [])
        <div class="senza-foto">Questa lettura non ha foto dei display.</div>
    @else
        @foreach($foto as $f)
            @php
                // La foto si rimpicciolisce per stare in un riquadro di
                // 680x440: senza misure esplicite dompdf la stampa alla sua
                // dimensione naturale, e una foto verticale da telefono
                // occuperebbe due pagine. Il riquadro è largo quanto il
                // foglio perché quello che conta è leggere il numero sul
                // display.
                $scala = min(680 / max($f['w'], 1), 440 / max($f['h'], 1), 1);
            @endphp
            <div class="foto">
                @if($loop->first)
                    <div class="section-title" style="margin-top: 4px;">Le foto dei display</div>
                @endif
                <div class="didascalia">
                    {{ $f['titolo'] }}
                    @if($f['valore'])
                        <span class="letto">letto: {{ $f['valore'] }}</span>
                    @endif
                </div>
                <div class="cornice">
                    <img src="{{ $f['src'] }}" width="{{ (int) round($f['w'] * $scala) }}" height="{{ (int) round($f['h'] * $scala) }}" alt="{{ $f['titolo'] }}">
                </div>
            </div>
        @endforeach
    @endif

    <div class="footer-note">Generato automaticamente il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
