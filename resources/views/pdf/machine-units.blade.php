<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Riepilogo macchine {{ $data->format('d/m/Y') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2937; line-height: 1.35; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        {{-- Il foglio si porta in giro: orizzontale, sette colonne, e le
             intestazioni si ripetono a ogni pagina (thead), altrimenti dalla
             seconda in poi le colonne non si capiscono piu'. --}}
        table.items { table-layout: fixed; }
        table.items th, table.items td { padding: 4px 5px; word-wrap: break-word; }
        table.items thead { display: table-header-group; }
        table.items tr { page-break-inside: avoid; }

        .c-matricola { width: 13%; }
        .c-modello   { width: 26%; }
        .c-manut     { width: 9%; }
        .c-presso    { width: 26%; }
        .c-citta     { width: 12%; }
        .c-cat       { width: 9%; }
        .c-stato     { width: 9%; }

        .customer-row td { background: #eef1f5 !important; font-weight: bold; font-size: 10px; padding: 6px 5px; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; }
        .customer-row .count { float: right; font-weight: normal; color: #6b7280; }
        .muted { color: #9ca3af; }
        .code { font-family: DejaVu Sans Mono, monospace; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td>
                <span class="label">Riepilogo macchine</span><br>
                <span class="value">{{ $titolo }}</span>
            </td>
            <td class="to-right">
                <span class="label">Stampato il</span><br>
                <span class="value">{{ $data->format('d/m/Y H:i') }}</span>
            </td>
        </tr>
    </table>

    @if($macchine->isEmpty())
        <p class="muted">Nessuna macchina corrisponde ai filtri applicati.</p>
    @else
        <div class="section-title">{{ $macchine->count() }} macchine</div>

        <table class="items">
            <thead>
                <tr>
                    <th class="c-matricola">Matricola</th>
                    <th class="c-modello">Modello</th>
                    <th class="c-manut">Manut.</th>
                    <th class="c-presso">Presso</th>
                    <th class="c-citta">Citta'</th>
                    <th class="c-cat">Categoria</th>
                    <th class="c-stato">Stato</th>
                </tr>
            </thead>
            <tbody>
            {{-- Raggruppate per cliente: il foglio si legge cliente per
                 cliente, che e' come si gira. Le macchine in magazzino
                 finiscono in fondo, sotto la loro intestazione. --}}
            @foreach($macchine->groupBy(fn ($m) => $m->currentCustomer?->company_name ?? 'In magazzino') as $cliente => $gruppo)
                <tr class="customer-row">
                    <td colspan="7">
                        {{ \App\Support\DisplayName::titleCase($cliente) }}
                        <span class="count">{{ $gruppo->count() }}</span>
                    </td>
                </tr>
                @foreach($gruppo as $macchina)
                    <tr>
                        <td class="code">{{ $macchina->serial_number ?: '—' }}</td>
                        <td>{{ $macchina->display_name }}</td>
                        {{-- Il codice che finirebbe davvero in rapportino: quello
                             della macchina se ce l'ha, altrimenti quello del
                             modello, e con la variante del pagante gia' applicata
                             (F2 -> F2GOPPION). Prima qui si leggeva il codice
                             grezzo del modello, che sui clienti Goppion, HTS e
                             Danieli era la tariffa sbagliata. --}}
                        <td class="code">{{ \App\Support\TariffeIntervento::manutenzione($macchina, $macchina->currentCustomer) ?: '—' }}</td>
                        <td>{{ \App\Support\DisplayName::titleCase($macchina->currentCustomer?->company_name) ?: '—' }}</td>
                        <td>{{ $macchina->currentCustomer?->city ?: '—' }}</td>
                        <td>{{ $etichetteCategoria[$macchina->type] ?? '—' }}</td>
                        <td>{{ $etichetteStato[$macchina->status] ?? 'In magazzino' }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer-note">
        Il codice manutenzione dice quale voce va in rapportino su questa
        macchina. Viene dal modello a catalogo, salvo quando la singola macchina
        ne dichiara uno suo, e porta gia' il suffisso di chi paga: GOPPION, HTS
        o DAN. Dove il suffisso manca, quella variante a catalogo non esiste e
        vale la tariffa piena.
    </p>
</body>
</html>
