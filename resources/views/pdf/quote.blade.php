<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Preventivo {{ $quote->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        .col-60 { width: 56%; padding-right: 20px; }
        .col-40 { width: 44%; }

        {{-- .info-box qui ha bordo tutt'intorno tranne sopra (si aggancia
             al section-title sopra di se'): nel partial condiviso .info-box
             ha il bordo completo, adatto ai riquadri "isolati" senza barra
             sopra (es. ordine-materiali). --}}
        .info-box { border-top: none; }

        .info-box .rental-note { margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 9px; line-height: 1.5; color: #1f2937; }
        .info-box .rental-note strong { color: #020F30; }

        table.items .option-row td { color: #4b5563; font-size: 9px; }

        .totals-table { width: 46%; margin-left: 54%; margin-top: 12px; border-collapse: collapse; }
        .totals-table th, .totals-table td { padding: 5px 10px; border: 1px solid #e5e7eb; font-size: 9.5px; }
        .totals-table th { background: #f9fafb; text-align: left; font-weight: bold; color: #374151; }
        .totals-table td { text-align: right; }
        /* Lo sconto e' il dato che deve saltare all'occhio (il risparmio
           del cliente); l'imponibile resta un gradino sotto ma comunque
           marcato (e' la base di calcolo); il totale finale un altro
           gradino sotto ancora - vedi thread 2026-08-12. */
        .totals-table .discount-row th, .totals-table .discount-row td { color: #047857; font-weight: bold; }
        .totals-table .subtotal-row th, .totals-table .subtotal-row td { font-weight: bold; font-size: 10.5px; border-top: 2px solid #020F30; }
        .totals-table .total-row th, .totals-table .total-row td { background: #f3f4f6; border-color: #d1d5db; color: #111827; font-weight: bold; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Preventivo</span><br><span class="value">{{ $quote->number }}</span></td>
            <td class="to-right"><span class="label">Data</span><br><span class="value">{{ $quote->date->format('d/m/Y') }}</span></td>
        </tr>
    </table>

    <table class="row-table">
        <tr>
            <td class="col-60">
                <div class="section-title">Dati cliente</div>
                <div class="info-box">
                    @if($quote->customer?->company_name)
                        <div class="customer-name">{{ $quote->customer->company_name }}</div>
                    @endif
                    <table>
                        @if($quote->customer?->billingCustomer)
                            <tr><td class="label">Fatturato a:</td><td>{{ $quote->customer->billingCustomer->full_name }}</td></tr>
                        @endif
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
                @if($quote->paymentMethodRelation?->name || ($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee))
                    <div class="section-title">Condizioni di pagamento</div>
                    <div class="info-box">
                        @if($quote->paymentMethodRelation?->name)
                            <div>{{ $quote->paymentMethodRelation->name }}</div>
                        @endif
                        @if($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee)
                            <div class="rental-note">
                                È disponibile il pagamento rateale tramite Grenke, con un canone di <strong>€ {{ number_format((float) $quote->rental_monthly_fee, 2, ',', '.') }} + IVA al mese</strong> per <strong>{{ $quote->rental_months }} mesi</strong>.
                            </div>
                        @endif
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
                <td class="numeric">
                    {{ $base->discount ?: 0 }}%
                    @if($base->discount)
                        <br><span class="sku-text">-€ {{ number_format(($base->quantity * $base->price) - $base->total, 2, ',', '.') }}</span>
                    @endif
                </td>
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
                    <td class="numeric">
                        {{ $option->discount ?: 0 }}%
                        @if($option->discount)
                            <br><span class="sku-text">-€ {{ number_format(($option->quantity * $option->price) - $option->total, 2, ',', '.') }}</span>
                        @endif
                    </td>
                    <td class="numeric">{{ $option->tax ?: 0 }}%</td>
                    <td class="numeric">€ {{ number_format($option->total, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>

    @php
        $productsGross = $quote->quoteProducts->sum(fn ($p) => $p->quantity * $p->price);
        $productsNet = $quote->quoteProducts->sum('total');
        $rowDiscountTotal = $productsGross - $productsNet;
    @endphp

    <div class="clearfix">
        <table class="totals-table">
            <tr><th colspan="3" style="text-align:left; background:#020F30; color:#fff;">Riepilogo</th></tr>
            @if($rowDiscountTotal > 0)
                <tr><th>Totale lordo</th><td colspan="2">€ {{ number_format($productsGross, 2, ',', '.') }}</td></tr>
                <tr class="discount-row"><th>Sconto prodotti</th><td colspan="2">-€ {{ number_format($rowDiscountTotal, 2, ',', '.') }}</td></tr>
            @endif
            @if($quote->discount > 0)
                <tr class="discount-row">
                    <th>Sconto generale</th>
                    <td>{{ number_format($quote->discount, 2, ',', '.') }}%</td>
                    <td>-€ {{ number_format($productsNet - $quote->subtotal, 2, ',', '.') }}</td>
                </tr>
            @endif
            <tr class="subtotal-row"><th>Imponibile</th><td colspan="2">€ {{ number_format($quote->subtotal, 2, ',', '.') }}</td></tr>
            <tr><th>IVA</th><td colspan="2">€ {{ number_format($quote->tax_total, 2, ',', '.') }}</td></tr>
            <tr class="total-row"><th>Totale IVA inclusa</th><td colspan="2">€ {{ number_format($quote->total, 2, ',', '.') }}</td></tr>
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

