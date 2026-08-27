<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rapportino {{ $report->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        {{-- Stessi identici stili base di pdf/quote.blade.php, condivisi via
             pdf/partials/document-styles.blade.php (.section-title,
             .info-box, table.items, .footer-note, ...): i PDF del sistema
             condividono un unico linguaggio visivo, il rapportino non deve
             avere il suo. Vedi anche pdf/ordine-materiali.blade.php per lo
             stesso principio applicato a un documento piu' semplice. --}}
        {{-- Tre colonne sulla stessa riga (luogo intervento, fatturazione,
             dati intervento): quando una cella ha piu' righe delle altre
             (es. fatturazione con tutti i dati fiscali contro luogo
             intervento con solo indirizzo) il bordo/sfondo che segna il box
             deve restare sulla <td> stessa, non su un div dentro — le celle
             di una riga si "stirano" sempre alla piu' alta, e cosi' anche
             bordo e sfondo la seguono. Un div interno invece si fermerebbe
             alla propria altezza naturale, lasciando i tre box sfalsati in
             fondo. .col-gap e' una cella vuota di distacco tra i tre
             (niente padding sulla stessa <td> che porta gia' il bordo: la
             spingerebbe dentro invece di separare i box). --}}
        .row-table { table-layout: fixed; }
        .col-half { width: 49%; }
        .col-gap { width: 2%; }
        {{-- Stessa riga larghezza asimmetrica del riquadro firma: la firma
             ha bisogno di piu' spazio del cartellino tecnico/tipo a fianco. --}}
        .col-sig { width: 65%; }
        .col-tech { width: 33%; }
        {{-- Piu' respiro dentro le schede (sede, nome, ecc. non piu'
             attaccati ai bordi): padding aumentato sia sulla cella che
             fa da cornice (.header-cell) sia sulle righe label/valore
             dentro (.info-box td). --}}
        {{-- .row-table td.header-cell (non solo .header-cell): serve piu'
             specificita' di ".row-table td", altrimenti il suo
             padding:0 vince ugualmente ed e' proprio quello che incollava
             nome/sede al bordo della scheda. --}}
        {{-- Il padding "respiro" vive sull'.info-box interno, non sulla
             td.header-cell: se lo si mette sulla cella si tira dentro anche
             la barra blu del .section-title, che risulta rientrata rispetto
             a tutte le altre barre blu del documento (Macchina, Note, Firma
             cliente, ...) che invece toccano il margine pagina — le barre
             blu devono restare tutte a filo tra loro. --}}
        .row-table td.header-cell { border: 1px solid #e5e7eb; background: #f9fafb; padding: 0; }
        .header-cell .section-title { margin: 0 0 8px; }
        .header-cell .info-box { border: none; background: transparent; padding: 14px 16px; }

        .info-box { padding: 10px 12px; }
        .info-box .customer-name { font-size: 11px; margin-bottom: 8px; padding-bottom: 6px; }
        .info-box td { padding: 3px 0; }
        .info-box td.label { padding-right: 8px; }

        .text-box { margin-top: 0; }
        .text-box p { margin: 0; padding: 8px 10px; background: #f9fafb; border: 1px solid #e5e7eb; white-space: pre-line; }

        {{-- Macchina/Problema riscontrato/Lavoro svolto unificati in un solo
             riquadro "Intervento" (un solo bandone blu invece di tre) —
             testo interno piu' grande (11px vs i 10px del body) perche' e'
             il contenuto che il cliente legge per capire cos'e' stato fatto,
             non deve essere il piu' piccolo del documento. --}}
        .intervento-box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 12px 14px; }
        .intervento-block + .intervento-block { margin-top: 12px; }
        .intervento-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #020F30; margin-bottom: 4px; }
        .intervento-text { font-size: 11px; line-height: 1.5; white-space: pre-line; }
        .intervento-box table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .intervento-box table td { padding: 3px 0; }
        .intervento-box table td.label { font-weight: bold; color: #4b5563; padding-right: 8px; white-space: nowrap; }

        {{-- margin-top:0 (non 4px come nel partial condiviso): la tabella
             deve stare incollata alla barra blu del titolo, come ogni altro
             box del documento (.info-box, .intervento-box), non con uno
             spazio in piu' solo qui. --}}
        table.items { margin-top: 0; }

        .signature-box { margin-top: 0; }
        .signature-box .frame { border: 1px solid #e5e7eb; background: #f9fafb; padding: 8px 10px; min-height: 90px; }
        .signature-box img { max-width: 260px; max-height: 90px; }
        .signature-box .placeholder { color: #9ca3af; font-style: italic; }
        .signature-box .caption { margin-top: 4px; font-size: 8.5px; color: #6b7280; }
        .signature-box .signer-name { margin-top: 4px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; }

        .section-gap { margin-top: 14px; }

        .footer-note { margin-top: 24px; }
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
            \App\Models\ServiceReport::TYPE_SANIFICAZIONE => 'Sanificazione',
        ];
        $hasMachineInfo = $report->machineProduct || $report->machine_serial_number || $report->machineUnit || $report->quote;
        // Stesso controllo gia' fatto dall'ImageEntry dell'infolist Filament
        // (ServiceReportResource): il path puo' restare in DB anche se il
        // file non c'e' piu' su disco (storage non persistito, dump SQL
        // senza gli upload, ...) — senza, dompdf prova comunque a caricare
        // un'immagine inesistente e stampa il testo alt al suo posto invece
        // del placeholder "Non ancora firmato".
        $hasSignatureFile = $report->customer_signature_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($report->customer_signature_path);
        // Il rapportino inviato via email al cliente non deve MAI mostrare
        // prezzi (solo il download interno dell'operatore li mostra) — vedi
        // i due chiamanti di questa view: ServiceReportController::pdf()
        // (download, prezzi ok) e l'azione "Invia" in ServiceReportResource
        // (email, $showPrices = false).
        $showPrices ??= true;
    @endphp

    {{-- Solo numero e data qui, come semplice testo (niente piu' un box a
         tutta larghezza per due soli campi corti — sproporzionato, quasi
         vuoto). Tecnico e tipo intervento sono scesi vicino alla firma in
         fondo — nei fac simile di settore il tecnico e' quasi sempre
         associato alla sua firma, non alla testata del documento (vedi es.
         moduli "Rapporto di intervento tecnico"), e il tipo intervento sta
         con la descrizione del lavoro, non con l'identificativo del
         documento. --}}
    <div style="margin-bottom: 12px; font-size: 10px; color: #4b5563;">
        <strong style="color: #020F30; font-size: 12px;">{{ $report->number }}</strong>
        &nbsp;&middot;&nbsp; {{ $report->intervention_date->format('d/m/Y') }}
    </div>

    <table class="row-table">
        <tr>
            <td class="col-half header-cell">
                <div class="section-title">Luogo intervento</div>
                <div class="info-box">
                    <div class="customer-name">{{ \App\Support\DisplayName::titleCase($report->customer->company_name) ?: \App\Support\DisplayName::titleCase($report->customer->full_name) }}</div>
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
            {{-- Sempre presente, anche quando chi paga e' lo stesso cliente
                 del luogo intervento a fianco (in quel caso ripete nome e
                 indirizzo): due colonne alla pari sulla stessa riga, invece
                 di far collassare il box in una riga piena a se stante. --}}
            <td class="col-half header-cell">
                <div class="section-title">Dati di fatturazione</div>
                <div class="info-box">
                    <div class="customer-name">{{ \App\Support\DisplayName::titleCase($recipient->company_name) ?: \App\Support\DisplayName::titleCase($recipient->full_name) }}</div>
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

    <div class="section-title">Intervento</div>
    <div class="intervento-box" style="margin-bottom: 14px;">
        @if($hasMachineInfo)
            <div class="intervento-block">
                <div class="intervento-label">Macchina</div>
                <table>
                    @php($machineModel = $report->machine_model_name)
                    @if($machineModel)
                        <tr><td class="label">Modello:</td><td>{{ $machineModel }}</td></tr>
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
            <div class="intervento-block">
                <div class="intervento-label">Problema riscontrato</div>
                <div class="intervento-text">{{ $report->problem_description }}</div>
            </div>
        @endif

        <div class="intervento-block">
            <div class="intervento-label">Lavoro svolto</div>
            <div class="intervento-text">{{ $report->work_performed }}</div>
        </div>
    </div>

    @if($report->notes)
        <div class="section-title">Note</div>
        <div class="text-box" style="margin-bottom: 14px;"><p>{{ $report->notes }}</p></div>
    @endif

    @if($report->materialsUsed->isNotEmpty())
        <div class="section-title">Ricambi/materiali utilizzati</div>
        <table class="items" style="margin-bottom: 14px;">
            <thead><tr><th class="center">Quantità</th><th>Materiale</th>@if($showPrices)<th class="numeric">Prezzo unit.</th><th class="numeric">Importo</th>@endif</tr></thead>
            <tbody>
            @foreach($report->materialsUsed as $part)
                <tr>
                    <td class="center">{{ $part->quantity }}</td>
                    <td>{{ $part->material->display_label }}</td>
                    @if($showPrices)
                        <td class="numeric">{{ $part->unit_cost_snapshot !== null ? '€ '.number_format($part->unit_cost_snapshot, 2, ',', '.') : '—' }}</td>
                        <td class="numeric">{{ $part->line_total_snapshot !== null ? '€ '.number_format($part->line_total_snapshot, 2, ',', '.') : '—' }}</td>
                    @endif
                </tr>
            @endforeach
            @if($showPrices && $report->materialsUsed->sum('line_total_snapshot') > 0)
                <tr>
                    <td colspan="3" class="numeric"><strong>Totale</strong></td>
                    <td class="numeric"><strong>€ {{ number_format($report->materialsUsed->sum('line_total_snapshot'), 2, ',', '.') }}</strong></td>
                </tr>
            @endif
            </tbody>
        </table>
    @endif

    {{-- Rapportini compilati prima del passaggio a Materiali avevano i ricambi
         salvati come Product (partsUsed) — sezione solo per lo storico. --}}
    @if($report->partsUsed->isNotEmpty())
        <div class="section-title">Ricambi/materiali utilizzati</div>
        <table class="items" style="margin-bottom: 14px;">
            <thead><tr><th class="center">Quantità</th><th>Prodotto</th>@if($showPrices)<th class="numeric">Prezzo</th>@endif</tr></thead>
            <tbody>
            @foreach($report->partsUsed as $part)
                <tr>
                    <td class="center">{{ $part->quantity }}</td>
                    <td>{{ $part->product->name }}</td>
                    @if($showPrices)
                        <td class="numeric">{{ $part->unit_cost_snapshot !== null ? '€ '.number_format($part->unit_cost_snapshot, 2, ',', '.') : '—' }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    {{-- Il tecnico va a fianco della firma (col-tech), non sopra come
         semplice riga di testo. La barra blu "Firma cliente" vive DENTRO la
         colonna della firma (col-sig), non sopra l'intera riga: coprirebbe
         anche la larghezza del cartellino tecnico, che non e' una firma del
         cliente e non deve stare sotto quel titolo. --}}
    <table class="row-table">
        <tr>
            <td class="col-sig">
                <div class="section-title">Firma cliente</div>
                <div class="signature-box">
                    <div class="frame">
                        @if($hasSignatureFile)
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
            </td>
            <td class="col-gap"></td>
            <td class="col-tech header-cell">
                <div class="info-box">
                    <table>
                        <tr><td class="label">Tecnico:</td><td>{{ $report->technician->name }}</td></tr>
                        <tr><td class="label">Tipo:</td><td>{{ $interventionTypeLabels[$report->intervention_type] ?? $report->intervention_type }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer-note">{{ $report->tenant->legal_name ?: $report->tenant->name }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
