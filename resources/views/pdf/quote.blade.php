<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Preventivo {{ $quote->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')

        .row-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .row-table td { border: none; padding: 0; vertical-align: top; }
        .col-60 { width: 56%; padding-right: 20px; }
        .col-40 { width: 44%; }

        .section-title { background: #020F30; color: #fff; padding: 5px 10px; font-size: 9.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
        .info-box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px 10px; }
        .info-box .customer-name { font-size: 12px; font-weight: bold; color: #020F30; margin-bottom: 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .info-box table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .info-box td { padding: 2px 0; }
        .info-box td.label { font-weight: 600; color: #4b5563; padding-right: 6px; white-space: nowrap; }

        .payment-box { margin-top: 8px; background: #020F30; color: #fff; padding: 8px 12px; font-size: 9.5px; }
        .payment-box strong { text-transform: uppercase; letter-spacing: .04em; }
        .rental-box { margin-top: 0; background: #fffbeb; border: 1px solid #fcd34d; border-left: 3px solid #f59e0b; border-top: none; color: #1f2937; padding: 8px 10px; font-size: 9px; line-height: 1.5; }
        .rental-box strong { color: #92400e; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; background: #f3f4f6; border: 1px solid #e5e7eb; padding: 6px 5px; font-size: 8px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: .03em; }
        table.items td { border: 1px solid #e5e7eb; padding: 6px 5px; vertical-align: top; }
        table.items td.numeric, table.items th.numeric { text-align: right; }
        table.items td.center, table.items th.center { text-align: center; }
        table.items .option-row td { color: #4b5563; font-size: 9px; }
        table.items .sku-text { color: #6b7280; font-size: 8.5px; }

        .totals-table { width: 46%; margin-left: 54%; margin-top: 12px; border-collapse: collapse; }
        .totals-table th, .totals-table td { padding: 5px 10px; border: 1px solid #e5e7eb; font-size: 9.5px; }
        .totals-table th { background: #f9fafb; text-align: left; font-weight: 600; color: #374151; }
        .totals-table td { text-align: right; }
        /* L'imponibile e' il dato che conta di piu' per chi legge, quindi
           resta il piu' evidente della tabella; il totale (IVA inclusa)
           e' bold ma su sfondo neutro, un gradino sotto. */
        .totals-table .subtotal-row th, .totals-table .subtotal-row td { background: #020F30; border-color: #020F30; color: #fff; font-size: 12px; font-weight: bold; }
        .totals-table .total-row th, .totals-table .total-row td { background: #f3f4f6; border-color: #d1d5db; color: #111827; font-size: 10px; font-weight: bold; }

        .notes-box { margin-top: 18px; padding: 10px 12px; background: #fffbeb; border: 1px solid #fcd34d; border-left: 3px solid #f59e0b; }
        .notes-box h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #92400e; margin: 0 0 4px; }
        .notes-box p { margin: 0; font-size: 11px;}

        .footer-note { margin-top: 24px; font-size: 8px; color: #9ca3af; text-align: center; }

        .clearfix::after { content: ""; display: table; clear: both; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />
    <table class="row-table">
        <tr>
            <td class="col-60">
                <div class="section-title">Dati cliente</div>
                <div class="info-box">
                    @if($quote->customer?->company_name)
                        <div class="customer-name">{{ $quote->customer->company_name }}</div>
                    @endif
                    <table>
                        @if($quote->customer?->first_name || $quote->customer?->last_name)
                            <tr><td class="label">Rif.to:</td><td>{{ trim("{$quote->customer->first_name} {$quote->customer->last_name}") }}</td></tr>
                        @endif
                        @if($quote->customer?->street || $quote->customer?->postal_code || $quote->customer?->city)
                            <tr><td class="label">Sede:</td><td>{{ trim("{$quote->customer->street}, {$quote->customer->postal_code} {$quote->customer->city}".($quote->customer->province ? " ({$quote->customer->province})" : ''), ' ,') }}</td></tr>
                        @endif
                        @if(filled($quote->customer?->emails))
                            <tr><td class="label">Email:</td><td>{{ implode(', ', $quote->customer->emails) }}</td></tr>
                        @endif
                        @if(filled($quote->customer?->phones))
                            <tr><td class="label">Tel:</td><td>{{ implode(', ', $quote->customer->phones) }}</td></tr>
                        @endif
                        @if($quote->customer?->pec)
                            <tr><td class="label">PEC:</td><td>{{ $quote->customer->pec }}</td></tr>
                        @endif
                        @if($quote->customer?->vat_number)
                            <tr><td class="label">P.IVA:</td><td>{{ $quote->customer->vat_number }}</td></tr>
                        @endif
                        @if($quote->customer?->tax_code)
                            <tr><td class="label">C.F.:</td><td>{{ $quote->customer->tax_code }}</td></tr>
                        @endif
                        @if($quote->customer?->sdi)
                            <tr><td class="label">SDI:</td><td>{{ $quote->customer->sdi }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
            <td class="col-40">
                <div class="section-title">Dati preventivo</div>
                <div class="info-box">
                    <table>
                        <tr><td class="label">Numero:</td><td><strong>{{ $quote->number }}</strong></td></tr>
                        <tr><td class="label">Data:</td><td>{{ $quote->date->format('d/m/Y') }}</td></tr>
                    </table>
                </div>
                @if($quote->paymentMethodRelation?->name)
                    <div class="payment-box">
                        <strong>Condizioni di pagamento</strong><br>
                        {{ $quote->paymentMethodRelation->name }}
                    </div>
                @endif
                @if($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee)
                    <div class="rental-box">
                        È disponibile il pagamento rateale tramite Grenke, con un canone di
                        <strong>€ {{ number_format((float) $quote->rental_monthly_fee, 2, ',', '.') }} + IVA al mese</strong>
                        per <strong>{{ $quote->rental_months }} mesi</strong>.
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Prodotto</th>
                <th class="center">Qtà</th>
                <th class="numeric">Prezzo unit.</th>
                <th class="numeric">Sconto</th>
                <th class="numeric">IVA</th>
                <th class="numeric">Imponibile</th>
            </tr>
        </thead>
        <tbody>
        @foreach($quote->quoteProducts->whereNull('parent_quote_product_id') as $base)
            <tr>
                <td>
                    <strong>{{ $base->product?->name ?? 'Prodotto rimosso dal catalogo' }}</strong>
                    @if($base->product?->sku)<br><span class="sku-text">SKU: {{ $base->product->sku }}</span>@endif
                </td>
                <td class="center">{{ rtrim(rtrim(number_format($base->quantity, 2, ',', '.'), '0'), ',') }}</td>
                <td class="numeric">€ {{ number_format($base->price, 2, ',', '.') }}</td>
                <td class="numeric">{{ $base->discount ?: 0 }}%</td>
                <td class="numeric">{{ $base->tax ?: 0 }}%</td>
                <td class="numeric">€ {{ number_format($base->total, 2, ',', '.') }}</td>
            </tr>
            @foreach($base->options as $option)
                <tr class="option-row">
                    <td>
                        ↳ {{ $option->product?->name ?? 'Prodotto rimosso dal catalogo' }}
                        @if($option->product?->sku)<br><span class="sku-text">SKU: {{ $option->product->sku }}</span>@endif
                    </td>
                    <td class="center">{{ rtrim(rtrim(number_format($option->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="numeric">€ {{ number_format($option->price, 2, ',', '.') }}</td>
                    <td class="numeric">{{ $option->discount ?: 0 }}%</td>
                    <td class="numeric">{{ $option->tax ?: 0 }}%</td>
                    <td class="numeric">€ {{ number_format($option->total, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>

    <div class="clearfix">
        <table class="totals-table">
            @if($quote->discount > 0)
                <tr><th>Sconto generale</th><td>{{ number_format($quote->discount, 2, ',', '.') }}%</td></tr>
            @endif
            <tr class="subtotal-row"><th>Imponibile</th><td>€ {{ number_format($quote->subtotal, 2, ',', '.') }}</td></tr>
            <tr><th>IVA</th><td>€ {{ number_format($quote->tax_total, 2, ',', '.') }}</td></tr>
            <tr class="total-row"><th>Totale IVA inclusa</th><td>€ {{ number_format($quote->total, 2, ',', '.') }}</td></tr>
        </table>
    </div>

    @if($quote->notes)
        <div class="notes-box">
            <h2>Descrizione attrezzatura</h2>
            {!! $quote->notes !!}
        </div>
    @endif

    <div class="footer-note">{{ $tenant?->legal_name ?: $tenant?->name }} &mdash; Questo documento non costituisce fattura</div>
    @include('pdf.partials.page-numbers')
</body>
</html>

