<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rapportino {{ $report->number }}</title>
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

        .status-badge { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; color: #fff; }

        .text-box { margin-top: 0; }
        .text-box p { margin: 0; padding: 8px 10px; background: #f9fafb; border: 1px solid #e5e7eb; white-space: pre-line; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; background: #f3f4f6; border: 1px solid #e5e7eb; padding: 6px 5px; font-size: 8px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: .03em; }
        table.items td { border: 1px solid #e5e7eb; padding: 6px 5px; vertical-align: top; }
        table.items td.numeric, table.items th.numeric { text-align: right; }

        .signature-box { margin-top: 4px; }
        .signature-box .frame { border: 1px solid #e5e7eb; background: #f9fafb; padding: 8px 10px; min-height: 90px; }
        .signature-box img { max-width: 260px; max-height: 90px; }
        .signature-box .placeholder { color: #9ca3af; font-style: italic; }
        .signature-box .caption { margin-top: 4px; font-size: 8.5px; color: #6b7280; }

        .section-gap { margin-top: 14px; }

        .footer-note { margin-top: 24px; font-size: 8px; color: #9ca3af; text-align: center; }

        .clearfix::after { content: ""; display: table; clear: both; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$report->tenant" />

    @php
        $recipient = $report->invoiceRecipient();
        $interventionTypeLabels = [
            \App\Models\ServiceReport::TYPE_INSTALLAZIONE => 'Installazione',
            \App\Models\ServiceReport::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione ordinaria',
            \App\Models\ServiceReport::TYPE_MANUTENZIONE_STRAORDINARIA => 'Manutenzione straordinaria',
            \App\Models\ServiceReport::TYPE_RIPARAZIONE => 'Riparazione',
            \App\Models\ServiceReport::TYPE_GARANZIA => 'Garanzia',
        ];
        $statusLabels = ['bozza' => 'Bozza', 'completato' => 'Completato', 'firmato' => 'Firmato', 'inviato' => 'Inviato'];
        $statusColors = ['bozza' => '#9ca3af', 'completato' => '#0ea5e9', 'firmato' => '#f59e0b', 'inviato' => '#16a34a'];
        $hasMachineInfo = $report->machineProduct || $report->machine_serial_number || $report->machineUnit || $report->comodatoMacchina || $report->quote;
    @endphp

    <table class="row-table">
        <tr>
            <td class="col-60">
                <div class="section-title">Dati cliente</div>
                <div class="info-box">
                    <div class="customer-name">{{ $report->customer->company_name ?: $report->customer->full_name }}</div>
                    <table>
                        @if($recipient->isNot($report->customer))
                            <tr><td class="label">Fatturato a:</td><td>{{ $recipient->company_name ?: $recipient->full_name }}</td></tr>
                        @endif
                        @if($report->customer->street || $report->customer->postal_code || $report->customer->city)
                            <tr><td class="label">Sede:</td><td>{{ trim("{$report->customer->street}, {$report->customer->postal_code} {$report->customer->city}".($report->customer->province ? " ({$report->customer->province})" : ''), ' ,') }}</td></tr>
                        @endif
                        @if(filled($report->customer->emails))
                            <tr><td class="label">Email:</td><td>{{ implode(', ', $report->customer->emails) }}</td></tr>
                        @endif
                        @if(filled($report->customer->phones))
                            <tr><td class="label">Tel:</td><td>{{ implode(', ', $report->customer->phones) }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
            <td class="col-40">
                <div class="section-title">Dati intervento</div>
                <div class="info-box">
                    <table>
                        <tr><td class="label">Numero:</td><td><strong>{{ $report->number }}</strong></td></tr>
                        <tr><td class="label">Data:</td><td>{{ $report->intervention_date->format('d/m/Y') }}</td></tr>
                        <tr><td class="label">Tecnico:</td><td>{{ $report->technician->name }}</td></tr>
                        <tr><td class="label">Tipo:</td><td>{{ $interventionTypeLabels[$report->intervention_type] ?? $report->intervention_type }}</td></tr>
                        @if($report->arrival_at)
                            <tr><td class="label">Arrivo:</td><td>{{ $report->arrival_at->format('H:i') }}</td></tr>
                        @endif
                        @if($report->departure_at)
                            <tr><td class="label">Uscita:</td><td>{{ $report->departure_at->format('H:i') }}</td></tr>
                        @endif
                        <tr>
                            <td class="label">Stato:</td>
                            <td>
                                <span class="status-badge" style="background: {{ $statusColors[$report->status] ?? '#9ca3af' }};">
                                    {{ $statusLabels[$report->status] ?? ucfirst($report->status) }}
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    @if($hasMachineInfo)
        <div class="section-title">Macchina</div>
        <div class="info-box section-gap" style="margin-bottom: 14px;">
            <table>
                @if($report->machineProduct)
                    <tr><td class="label">Modello:</td><td>{{ $report->machineProduct->name }}</td></tr>
                @endif
                @if($report->machine_serial_number || $report->machineUnit?->serial_number)
                    <tr><td class="label">Matricola:</td><td>{{ $report->machine_serial_number ?: $report->machineUnit?->serial_number }}</td></tr>
                @endif
                @if($report->comodatoMacchina)
                    <tr><td class="label">Comodato:</td><td>{{ $report->comodatoMacchina->nome_macchina }}</td></tr>
                @endif
                @if($report->quote)
                    <tr><td class="label">Preventivo:</td><td>{{ $report->quote->number }}</td></tr>
                @endif
            </table>
        </div>
    @endif

    @if($report->problem_description)
        <div class="section-title">Problema riscontrato</div>
        <div class="text-box" style="margin-bottom: 14px;"><p>{{ $report->problem_description }}</p></div>
    @endif

    <div class="section-title">Lavoro svolto</div>
    <div class="text-box" style="margin-bottom: 14px;"><p>{{ $report->work_performed }}</p></div>

    @if($report->partsUsed->isNotEmpty())
        <div class="section-title">Ricambi/materiali utilizzati</div>
        <table class="items" style="margin-bottom: 14px;">
            <thead><tr><th>Prodotto</th><th class="numeric">Quantità</th></tr></thead>
            <tbody>
            @foreach($report->partsUsed as $part)
                <tr><td>{{ $part->product->name }}</td><td class="numeric">{{ $part->quantity }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">Firma cliente</div>
    <div class="signature-box">
        <div class="frame">
            @if($report->customer_signature_path)
                <img src="{{ public_path('storage/'.$report->customer_signature_path) }}" alt="Firma">
            @else
                <span class="placeholder">Non ancora firmato</span>
            @endif
        </div>
        @if($report->signed_at)
            <div class="caption">Firmato il {{ $report->signed_at->format('d/m/Y H:i') }}</div>
        @endif
    </div>

    <div class="footer-note">{{ $report->tenant->legal_name ?: $report->tenant->name }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
