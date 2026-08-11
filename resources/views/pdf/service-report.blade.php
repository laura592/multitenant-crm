<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rapportino {{ $report->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')

        .row-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; table-layout: fixed; }
        .row-table td { border: none; padding: 0; vertical-align: top; }
        .col-50 { width: 48%; }
        .col-gap { width: 4%; }

        {{-- Dati cliente/Dati di fatturazione sono due celle della stessa
             riga: quando una ha piu' righe dell'altra (dati fiscali completi
             da un lato, solo l'indirizzo dall'altro) il .info-box piu' corto
             si fermava prima, bordo inferiore sfalsato — height:100% sul
             child non e' un'opzione, dompdf lo risolve male dentro una
             cella (contenuto troncato su piu' pagine). Il bordo/sfondo di
             una <td> invece copre SEMPRE l'intera riga "stirata" alla cella
             piu' alta, quindi qui il box vive sulla cella stessa
             (.header-cell) invece che su un div dentro: niente percentuali,
             stesso risultato in modo affidabile. .col-gap e' una cella
             vuota di distacco (non si puo' usare padding sulla stessa <td>
             che porta gia' il bordo: lo spingerebbe dentro invece di
             separare i due box). --}}
        .header-cell { border: 1px solid #e5e7eb; background: #f9fafb; padding: 0; }
        .header-cell .section-title { margin-bottom: 0; }
        .header-cell .info-box { border: none; background: transparent; padding: 8px 10px; }

        {{-- Stessa idea della card "Panoramica rapida" nella vista rapportino
             (sfondo chiaro, valori in evidenza, niente riquadro scuro): qui
             a differenza del resto del documento (sezioni con .section-title
             scura) perche' e' il primo blocco che si legge, deve restare
             leggero invece di aprire subito su un modulo d'ufficio. --}}
        .hero-strip { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 16px; margin-bottom: 14px; }
        {{-- table-layout:fixed con larghezze esplicite: senza, le 4 colonne
             si stringevano al proprio contenuto e lo spazio in eccesso
             finiva tutto in coda all'ultima (bordo destro della card molto
             lontano dal contenuto, mai proporzionato). Larghezze tarate sul
             contenuto piu' lungo plausibile in ciascuna (nome tecnico,
             "Manutenzione straordinaria" per tipo). --}}
        .hero-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .hero-table td { padding: 0 14px 0 0; border-right: 1px solid #e2e8f0; vertical-align: top; }
        .hero-table td:last-child { border-right: none; padding-right: 0; }
        .hero-table .hero-col-numero { width: 18%; }
        .hero-table .hero-col-data { width: 14%; }
        .hero-table .hero-col-tecnico { width: 34%; }
        .hero-table .hero-col-tipo { width: 34%; }
        .hero-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 600; margin-bottom: 3px; }
        .hero-value { font-size: 11px; font-weight: 700; color: #020F30; }

        .section-title { background: #020F30; color: #fff; padding: 5px 10px; font-size: 9.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
        .info-box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px 10px; }
        .info-box .customer-name { font-size: 12px; font-weight: bold; color: #020F30; margin-bottom: 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .info-box table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .info-box td { padding: 2px 0; }
        .info-box td.label { font-weight: 600; color: #4b5563; padding-right: 6px; white-space: nowrap; }

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
        .signature-box .signer-name { margin-top: 4px; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }

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
        $hasMachineInfo = $report->machineProduct || $report->machine_serial_number || $report->machineUnit || $report->quote;
    @endphp

    {{-- Prima cosa che si legge: riepilogo intervento in stile "hero" leggero
         (stessa logica della card "Panoramica rapida" nella vista rapportino),
         non un altro riquadro scuro come le sezioni sotto. --}}
    <div class="hero-strip">
        <table class="hero-table">
            <tr>
                <td class="hero-col-numero">
                    <div class="hero-label">Numero</div>
                    <div class="hero-value">{{ $report->number }}</div>
                </td>
                <td class="hero-col-data">
                    <div class="hero-label">Data</div>
                    <div class="hero-value">{{ $report->intervention_date->format('d/m/Y') }}</div>
                </td>
                <td class="hero-col-tecnico">
                    <div class="hero-label">Tecnico</div>
                    <div class="hero-value">{{ $report->technician->name }}</div>
                </td>
                <td class="hero-col-tipo">
                    <div class="hero-label">Tipo</div>
                    <div class="hero-value">{{ $interventionTypeLabels[$report->intervention_type] ?? $report->intervention_type }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="row-table">
        <tr>
            <td class="col-50 header-cell">
                <div class="section-title">Dati cliente</div>
                <div class="info-box">
                    <div class="customer-name">{{ $report->customer->company_name ?: $report->customer->full_name }}</div>
                    <table>
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
            <td class="col-gap"></td>
            <td class="col-50 header-cell">
                <div class="section-title">Dati di fatturazione</div>
                <div class="info-box">
                    <div class="customer-name">{{ $recipient->company_name ?: $recipient->full_name }}</div>
                    <table>
                        @if($recipient->street || $recipient->postal_code || $recipient->city)
                            <tr><td class="label">Sede:</td><td>{{ trim("{$recipient->street}, {$recipient->postal_code} {$recipient->city}".($recipient->province ? " ({$recipient->province})" : ''), ' ,') }}</td></tr>
                        @endif
                        @if($recipient->pec)
                            <tr><td class="label">PEC:</td><td>{{ $recipient->pec }}</td></tr>
                        @endif
                        @if($recipient->vat_number)
                            <tr><td class="label">P.IVA:</td><td>{{ $recipient->vat_number }}</td></tr>
                        @endif
                        @if($recipient->tax_code)
                            <tr><td class="label">C.F.:</td><td>{{ $recipient->tax_code }}</td></tr>
                        @endif
                        @if($recipient->sdi)
                            <tr><td class="label">SDI:</td><td>{{ $recipient->sdi }}</td></tr>
                        @endif
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

    @if($report->notes)
        <div class="section-title">Note</div>
        <div class="text-box" style="margin-bottom: 14px;"><p>{{ $report->notes }}</p></div>
    @endif

    @if($report->materialsUsed->isNotEmpty())
        <div class="section-title">Ricambi/materiali utilizzati</div>
        <table class="items" style="margin-bottom: 14px;">
            <thead><tr><th>Materiale</th><th>Codice prezzo</th><th class="numeric">Quantità</th></tr></thead>
            <tbody>
            @foreach($report->materialsUsed as $part)
                <tr><td>{{ $part->material->display_label }}</td><td>&nbsp;</td><td class="numeric">{{ $part->quantity }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    {{-- Rapportini compilati prima del passaggio a Materiali avevano i ricambi
         salvati come Product (partsUsed) — sezione solo per lo storico. --}}
    @if($report->partsUsed->isNotEmpty())
        <div class="section-title">Ricambi/materiali utilizzati</div>
        <table class="items" style="margin-bottom: 14px;">
            <thead><tr><th>Prodotto</th><th>Codice prezzo</th><th class="numeric">Quantità</th></tr></thead>
            <tbody>
            @foreach($report->partsUsed as $part)
                <tr><td>{{ $part->product->name }}</td><td>&nbsp;</td><td class="numeric">{{ $part->quantity }}</td></tr>
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
        @if($report->customer_signature_name)
            <div class="signer-name">{{ $report->customer_signature_name }}</div>
        @endif
        @if($report->signed_at)
            <div class="caption">Firmato il {{ $report->signed_at->format('d/m/Y H:i') }}</div>
        @endif
    </div>

    <div class="footer-note">{{ $report->tenant->legal_name ?: $report->tenant->name }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
