<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Dati del contratto {{ $contratto['nome'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.45; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        {{-- Deve stare in una pagina anche con una dozzina di optional: e'
             il frontespizio del contratto, non un documento a se'. --}}
        .intro { margin: 6px 0 8px; color: #4b5563; font-size: 9px; }
        .blocco { margin-top: 8px; }
        .info-box { padding: 5px 10px; }
        .info-box .customer-name { margin-bottom: 3px; padding-bottom: 2px; }
        .info-box td { padding: 1px 0; }
        table.items { table-layout: fixed; }
        table.items td { padding: 3px 5px; font-size: 9px; }
        table.items th { padding: 4px 5px; }
        .c-voce { width: 58%; } .c-perche { width: 20%; } .c-prezzo { width: 22%; }
        .canone td { font-size: 12px; font-weight: bold; color: #020F30; background: #eef1f5; }
        .avviso { margin-top: 6px; padding: 5px 10px; font-size: 9px; border-left: 3px solid #f59e0b; background: #fffbeb; }
        .firma { margin-top: 18px; width: 100%; }
        .firma td { width: 50%; padding-top: 4px; border-top: 1px solid #9ca3af; font-size: 9px; color: #6b7280; }
        .firma td + td { padding-left: 24px; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Dati del contratto</span><br><span class="value">{{ $contratto['nome'] }}</span></td>
            <td class="to-right">
                @if($preventivo?->number)
                    <span class="label">Preventivo</span> <span class="value">{{ $preventivo->number }}</span><br>
                @endif
                <span class="label">Data</span> <span class="value">{{ $data->format('d/m/Y') }}</span>
            </td>
        </tr>
    </table>

    <p class="intro">
        Questa pagina è parte integrante del contratto {{ $contratto['nome'] }} che segue, e ne riporta
        i dati: il cliente, la macchina e il calcolo del corrispettivo annuo previsto dal contratto.
    </p>

    <div class="section-title">Cliente</div>
    <div class="info-box">
        <div class="customer-name">{{ \App\Support\DisplayName::titleCase($cliente?->company_name) }}</div>
        <table>
            @if($cliente?->street || $cliente?->city)
                <tr><td class="label">Sede:</td><td>{{ trim("{$cliente->street}, {$cliente->postal_code} {$cliente->city}".($cliente->province ? " ({$cliente->province})" : ''), ' ,') }}</td></tr>
            @endif
            @if($cliente?->vat_number)
                <tr><td class="label">P.IVA:</td><td>{{ $cliente->vat_number }}</td></tr>
            @endif
            @if($cliente?->tax_code)
                <tr><td class="label">C.F.:</td><td>{{ $cliente->tax_code }}</td></tr>
            @endif
        </table>
    </div>

    <div class="blocco">
        <div class="section-title">Macchina</div>
        <div class="info-box">
            <table>
                <tr><td class="label">Modello:</td><td>{{ $macchina?->name }}</td></tr>
                @if($macchina?->sku)
                    <tr><td class="label">Codice:</td><td>{{ $macchina->sku }}</td></tr>
                @endif
                <tr><td class="label">Matricola:</td><td>______________________________</td></tr>
            </table>
        </div>
    </div>

    <div class="blocco">
        <div class="section-title">Corrispettivo annuo</div>
        <table class="items">
            <thead>
                <tr>
                    <th class="c-voce">Voce a listino ufficiale Franke</th>
                    <th class="c-perche">Rientra come</th>
                    <th class="c-prezzo numeric">Listino</th>
                </tr>
            </thead>
            <tbody>
                @foreach($contratto['voci'] as $voce)
                    <tr>
                        <td>{{ $voce['nome'] }}</td>
                        <td>{{ $voce['perche'] }}</td>
                        <td class="numeric">€ {{ number_format($voce['prezzo'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="2"><strong>Valore di listino</strong></td>
                    <td class="numeric"><strong>€ {{ number_format($contratto['base'], 2, ',', '.') }}</strong></td>
                </tr>
                <tr class="canone">
                    <td colspan="2">Canone annuo: {{ number_format($contratto['percentuale'], 0, ',', '.') }}% del valore di listino, IVA esclusa</td>
                    <td class="numeric">€ {{ number_format($contratto['canone'], 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        @if($dalSecondoAnno)
            <div class="avviso">
                Il servizio {{ $contratto['nome'] }} si attiva dal secondo anno di vita della macchina, alla scadenza
                della garanzia del costruttore (art. 2).
            </div>
        @endif

        @if($serveAcqua)
            <div class="avviso">
                L'attivazione richiede un sistema di trattamento acqua dedicato alla macchina, mantenuto dal Cliente
                (art. 3).
            </div>
        @endif
    </div>

    <table class="firma">
        <tr>
            <td>Data di attivazione</td>
            <td>Il Cliente (timbro e firma)</td>
        </tr>
    </table>
</body>
</html>
