<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Offerta caffè — {{ \App\Support\DisplayName::titleCase($cliente->company_name) }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        .gruppo { margin-top: 16px; }
        table.items { table-layout: fixed; }
        .c-prodotto { width: 62%; }
        .c-formato  { width: 18%; }
        .c-prezzo   { width: 20%; }
        .prezzo { font-weight: bold; }
        .muted { color: #9ca3af; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Documento</span><br><span class="value">Offerta caffè{{ $numero ? ' n. '.$numero : '' }}</span></td>
            <td class="to-right">
                <span class="label">Data</span><br><span class="value">{{ $data->format('d/m/Y') }}</span>
                @if($validaFino)
                    <br><span class="label">Valida fino al</span> <span class="value">{{ $validaFino->format('d/m/Y') }}</span>
                @endif
            </td>
        </tr>
    </table>

    <div class="section-title">Dati cliente</div>
    <div class="info-box">
        <div class="customer-name">{{ \App\Support\DisplayName::titleCase($cliente->company_name) }}</div>
        <table>
            @if($cliente->street || $cliente->postal_code || $cliente->city)
                <tr><td class="label">Sede:</td><td>{{ trim("{$cliente->street}, {$cliente->postal_code} {$cliente->city}".($cliente->province ? " ({$cliente->province})" : ''), ' ,') }}</td></tr>
            @endif
            @if($cliente->vat_number)
                <tr><td class="label">P.IVA:</td><td>{{ $cliente->vat_number }}</td></tr>
            @endif
            @if(filled($cliente->emails))
                <tr><td class="label">Email:</td><td>{{ implode(', ', $cliente->emails) }}</td></tr>
            @endif
        </table>
    </div>

    {{-- Niente quantita' ne' totale: l'offerta caffe' e' un listino per
         questo cliente, non una fornitura (vedi App\Support\Pdf\OffertaCaffePdf). --}}
    @forelse($gruppi as $gruppo => $righe)
        <div class="gruppo">
            <div class="section-title">{{ $gruppo }}</div>
            <table class="items">
                <thead>
                    <tr>
                        <th class="c-prodotto">Prodotto</th>
                        <th class="c-formato">Formato</th>
                        <th class="c-prezzo numeric">Prezzo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($righe as $riga)
                        <tr>
                            <td>{{ $riga['nome'] }}</td>
                            <td>{!! $riga['formato'] ? e($riga['formato']) : '<span class="muted">—</span>' !!}</td>
                            <td class="numeric prezzo">€ {{ number_format($riga['prezzo'], 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="muted">Nessun prodotto in questa offerta.</p>
    @endforelse

    @if($note)
        <div class="notes-box">
            <h2>Note</h2>
            <p>{!! nl2br(e($note)) !!}</p>
        </div>
    @endif

    @include('pdf.partials.page-numbers')
</body>
</html>
