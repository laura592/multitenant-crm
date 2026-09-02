{{-- Riepilogo degli interventi di un periodo, orizzontale.

     Serve a controllare un mese di lavoro senza aprire un rapportino alla
     volta, quindi e' fitto per scelta: font piccolo, righe strette, e gli
     articoli su una riga sola separati da virgola invece che elencati. --}}
@php
    $pagante = function ($r) {
        // invoiceRecipient() esplode se il cliente e' stato eliminato: in un
        // riepilogo di cento righe una riga rotta non deve far saltare la
        // stampa intera.
        try {
            $chi = $r->invoiceRecipient();
        } catch (\Throwable) {
            return null;
        }

        return $chi && $chi->id !== $r->customer?->id ? $chi->company_name : null;
    };
    $totale = $rapportini->sum(fn ($r) => (float) $r->materialsUsed->sum('line_total_snapshot'));
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7.5pt; color: #111; }
        h1 { font-size: 12pt; margin: 0 0 2px; }
        .periodo { color: #555; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: .3px;
             color: #555; border-bottom: 1px solid #999; padding: 3px 4px; }
        td { padding: 3px 4px; border-bottom: .5px solid #ddd; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .num { text-align: right; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .muted { color: #888; }
        tfoot td { border-top: 1px solid #999; border-bottom: none; font-weight: bold; padding-top: 5px; }
    </style>
</head>
<body>
    <h1>Riepilogo interventi{{ $tenant?->name ? ' — '.$tenant->name : '' }}</h1>
    <div class="periodo">
        dal {{ $da->format('d/m/Y') }} al {{ $a->format('d/m/Y') }}
        · {{ $rapportini->count() }} {{ $rapportini->count() === 1 ? 'intervento' : 'interventi' }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:52px">Data</th>
                <th style="width:74px">Numero</th>
                <th style="width:66px">Scheda</th>
                <th style="width:150px">Cliente</th>
                <th style="width:120px">Paga</th>
                <th style="width:130px">Macchina</th>
                <th>Articoli</th>
                @if($showPrices)<th class="num" style="width:56px">Importo</th>@endif
            </tr>
        </thead>
        <tbody>
        @forelse($rapportini as $r)
            @php($chi = $pagante($r))
            <tr>
                <td class="nowrap">{{ $r->intervention_date?->format('d/m/y') }}</td>
                <td class="nowrap">{{ $r->number }}</td>
                <td class="nowrap">{{ $r->gestionale_number ?: '—' }}</td>
                <td>{{ \App\Support\DisplayName::titleCase($r->customer?->company_name) ?: '—' }}</td>
                {{-- Si scrive solo quando paga qualcun altro: ripetere il
                     cliente su ogni riga sprecherebbe la colonna. --}}
                <td>{{ $chi ? \App\Support\DisplayName::titleCase($chi) : '' }}</td>
                <td>
                    {{ $r->machineUnit?->serial_number ?: ($r->machine_serial_number ?: '—') }}
                    @if($r->machineUnit?->model_name)
                        <div class="muted">{{ $r->machineUnit->model_name }}</div>
                    @endif
                </td>
                <td>
                    @php($righe = $r->materialsUsed)
                    @if($righe->isEmpty())
                        <span class="muted">—</span>
                    @else
                        {{ $righe->map(function ($riga) {
                            $q = rtrim(rtrim(number_format((float) $riga->quantity, 2, ',', '.'), '0'), ',');
                            return ($q !== '1' ? $q.'× ' : '').($riga->material?->code ?? '?');
                        })->implode(', ') }}
                    @endif
                </td>
                @if($showPrices)
                    @php($importo = (float) $righe->sum('line_total_snapshot'))
                    <td class="num">{{ $importo > 0 ? number_format($importo, 2, ',', '.') : '' }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $showPrices ? 8 : 7 }}" class="muted">Nessun intervento nel periodo.</td></tr>
        @endforelse
        </tbody>
        @if($showPrices && $totale > 0)
            <tfoot>
                <tr>
                    <td colspan="7" class="num">Totale</td>
                    <td class="num">{{ number_format($totale, 2, ',', '.') }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
