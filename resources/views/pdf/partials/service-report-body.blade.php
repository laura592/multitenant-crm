{{-- Il rapportino, senza l'involucro HTML: cosi' pdf/service-report.blade.php
     lo stampa da solo e pdf/rapportini-per-fattura.blade.php lo ripete per
     ogni rapportino della fattura. Variabili attese: $report, $showPrices,
     $showArticoli. La numerazione delle pagine sta fuori (va una volta per
     documento, non una per rapportino). --}}
    <x-pdf-letterhead :tenant="$report->tenant" />

    @php
        $recipient = $report->invoiceRecipient();
        $interventionTypeLabels = \App\Filament\Resources\ServiceReportResource::interventionTypeLabels();
        $hasMachineInfo = $report->machineProduct || $report->machine_serial_number || $report->machineUnit || $report->quote;
        // Stesso controllo gia' fatto dall'ImageEntry dell'infolist Filament
        // (ServiceReportResource): il path puo' restare in DB anche se il
        // file non c'e' piu' su disco (storage non persistito, dump SQL
        // senza gli upload, ...) — senza, dompdf prova comunque a caricare
        // un'immagine inesistente e stampa il testo alt al suo posto invece
        // del placeholder "Non ancora firmato".
        $signatureFile = \App\Support\Rapportini\FirmaCliente::percorsoFile($report->customer_signature_path);
        $hasSignatureFile = $signatureFile !== null;
        // Il rapportino inviato via email al cliente non deve MAI mostrare
        // prezzi (solo il download interno dell'operatore li mostra) — vedi
        // i due chiamanti di questa view: ServiceReportController::pdf()
        // (download, prezzi ok) e l'azione "Invia" in ServiceReportResource
        // (email, $showPrices = false).
        $showPrices ??= true;
        // La copia "senza articoli" nasce per l'invio al cliente da parte del
        // tecnico (04/09/2026): il cliente firma di aver ricevuto
        // l'intervento, non l'elenco dei ricambi montati, che e' materia di
        // chi fattura. Nel download interno resta tutto.
        $showArticoli ??= true;
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
                    @php($vieLavate = $report->countsAsLavaggio() ? $report->totalLinesWashed() : null)
                    @if($vieLavate !== null)
                        <tr><td class="label">Vie lavate:</td><td>{{ $vieLavate }}</td></tr>
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

    @if($showArticoli && $report->materialsUsed->isNotEmpty())
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
    @if($showArticoli && $report->partsUsed->isNotEmpty())
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
                            <img src="{{ $signatureFile }}" alt="Firma">
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
