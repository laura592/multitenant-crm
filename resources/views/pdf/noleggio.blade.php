<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Contratto di noleggio operativo — {{ $noleggio->customer?->company_name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.55; }
        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        h1.titolo { font-size: 15px; color: #020F30; margin: 18px 0 2px; }
        p.sottotitolo { margin: 0 0 14px; color: #4b5563; }
        h2.art { font-size: 11px; color: #020F30; margin: 14px 0 3px; }
        ul.voci { margin: 2px 0 2px 16px; padding: 0; }
        ul.voci li { margin-bottom: 2px; }
        /* Il canone e' il numero che si cerca: va trovato senza leggere. */
        .canone-box { border: 1.5px solid #020F30; background: #f0f4fa; padding: 8px 12px; margin: 6px 0 2px; }
        .canone-box .cifra { font-size: 17px; font-weight: bold; color: #020F30; }
        .firme { page-break-inside: avoid; margin-top: 24px; }
        .riga-firma { border-top: 1px solid #9ca3af; padding-top: 3px; width: 46%; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    @php
        $azienda = $tenant?->legal_name ?: ($tenant?->name ?: config('app.name'));
        $forniture = $noleggio->forniture;
        $perGruppo = $forniture->groupBy('gruppo');
        $mesi = (int) $noleggio->mesi;
        $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');
        // Nel contratto vanno le QUANTITA', non i prezzi: il cliente deve
        // sapere cosa gli spetta, non come e' composto il nostro margine. Il
        // dettaglio economico resta nel CRM.
        // "13,742 500 g di Cioccolato" non si legge. Quando l'unita' e' una
        // confezione (contiene una cifra: "500 g", "250 g") ci vuole il "per";
        // quando e' una misura o un pezzo, no.
        $quantita = function ($r) {
            $q = rtrim(rtrim(number_format((float) $r->quantita, 2, ',', '.'), '0'), ',');
            $confezione = (bool) preg_match('/\d/', (string) $r->unita);

            return $r->voce.' '.$q.($confezione ? ' × ' : ' ').$r->unita;
        };
    @endphp

    <h1 class="titolo">CONTRATTO DI NOLEGGIO OPERATIVO</h1>
    <p class="sottotitolo">Fornitura in uso di attrezzatura professionale per la somministrazione di caffè,
        con assistenza tecnica e forniture comprese nel canone.</p>

    <div class="info-box">
        <strong>Tra</strong><br>
        {{ $azienda }}@if($tenant?->pdfAddressLine()), {{ $tenant->pdfAddressLine() }}@endif — di seguito «il Fornitore»<br><br>
        <strong>e</strong><br>
        {{ $noleggio->customer?->company_name ?: trim($noleggio->customer?->first_name.' '.$noleggio->customer?->last_name) }}@if($noleggio->customer?->street), {{ $noleggio->customer->street }}@endif@if($noleggio->customer?->postal_code), {{ $noleggio->customer->postal_code }} {{ $noleggio->customer->city }}@if(filled($noleggio->customer?->province)) ({{ $noleggio->customer->province }})@endif
        @endif@if($noleggio->customer?->vat_number)<br>P. IVA {{ $noleggio->customer->vat_number }}@endif — di seguito «il Cliente»
    </div>

    <h2 class="art">Art. 1 – Oggetto</h2>
    <p>Il Fornitore concede al Cliente, in noleggio operativo, l'attrezzatura di seguito indicata, unitamente
        all'assistenza tecnica e alle forniture di consumo previste dall'art. 4.</p>
    <div class="info-box">
        <strong>{{ $noleggio->descrizione }}</strong>
        @if($noleggio->machineUnit)<br>Matricola {{ $noleggio->machineUnit->serial_number }}@endif
    </div>

    <h2 class="art">Art. 2 – Durata e decorrenza</h2>
    <p>Il contratto ha durata di <strong>{{ $mesi }} mesi</strong>
        @if($noleggio->data_inizio) con decorrenza dal {{ $noleggio->data_inizio->format('d/m/Y') }}@endif.
        Alla scadenza si intende concluso, salvo rinnovo concordato per iscritto fra le parti.</p>

    <h2 class="art">Art. 3 – Canone</h2>
    <div class="canone-box">
        <span class="cifra">{{ $eur($noleggio->canone) }}</span> al mese, IVA esclusa
    </div>
    <p>Il canone è comprensivo di quanto previsto dall'art. 4 e si intende dovuto per l'intera durata del
        contratto. Impegno complessivo su {{ $mesi }} mesi: {{ $eur((float) $noleggio->canone * $mesi) }} + IVA.</p>

    <h2 class="art">Art. 4 – Cosa è compreso nel canone</h2>
    <ul class="voci">
        <li>La <strong>disponibilità dell'attrezzatura</strong> indicata all'art. 1 per tutta la durata del contratto.</li>
        <li>La <strong>consegna, l'installazione e l'allacciamento</strong> dell'attrezzatura.</li>
        <li>L'<strong>assistenza tecnica full-service</strong>: manutenzioni programmate, interventi su chiamata,
            fornitura e sostituzione dei ricambi, manodopera e trasferte del personale tecnico, supporto telefonico.</li>
        @foreach ($perGruppo as $gruppo => $righe)
            <li>La fornitura mensile di <strong>{{ mb_strtolower(\App\Models\NoleggioFornitura::gruppiLabels()[$gruppo] ?? $gruppo) }}</strong>,
                nei quantitativi di @foreach ($righe as $r){{ $quantita($r) }}@if(! $loop->last); @endif@endforeach.</li>
        @endforeach
        @if ($forniture->isEmpty())
            <li>Le forniture di consumo nei quantitativi concordati fra le parti.</li>
        @endif
    </ul>

    <h2 class="art">Art. 5 – Cosa non è compreso</h2>
    <ul class="voci">
        <li>Le <strong>predisposizioni</strong> necessarie all'installazione — punto acqua, scarico e alimentazione
            elettrica — che restano a carico del Cliente e devono essere realizzate prima dell'intervento.</li>
        <li>I <strong>quantitativi di fornitura eccedenti</strong> quelli indicati all'art. 4, che sono fatturati a consumo.</li>
        <li>Il <strong>latte</strong> e gli altri ingredienti non espressamente elencati all'art. 4.</li>
        <li>Le riparazioni rese necessarie da <strong>uso improprio, negligenza, manomissioni</strong> o interventi
            eseguiti da personale non autorizzato dal Fornitore.</li>
        <li>I <strong>consumi di energia elettrica e acqua</strong> e gli oneri di legge.</li>
    </ul>

    <h2 class="art">Art. 6 – Proprietà e restituzione</h2>
    <p>L'attrezzatura resta di <strong>esclusiva proprietà del Fornitore</strong> per tutta la durata del contratto.
        Il Cliente non può cederla, darla in uso a terzi, spostarla in altra sede né sottoporla a modifiche senza
        autorizzazione scritta. Alla scadenza l'attrezzatura va restituita nello stato in cui è stata consegnata,
        salvo il normale deperimento d'uso.</p>

    <h2 class="art">Art. 7 – Obblighi del Cliente</h2>
    <p>Il Cliente si impegna a utilizzare l'attrezzatura secondo le istruzioni ricevute, a eseguire le operazioni
        quotidiane di pulizia previste dal costruttore, a segnalare tempestivamente malfunzionamenti e a consentire
        l'accesso al personale tecnico per gli interventi previsti.</p>

    <h2 class="art">Art. 8 – Recesso anticipato</h2>
    <p>In caso di recesso del Cliente prima della scadenza restano dovuti i canoni residui, salvo diverso accordo
        scritto fra le parti.</p>

    <h2 class="art">Art. 9 – Legge applicabile e foro competente</h2>
    <p>Il presente contratto è regolato dalla legge italiana. Per ogni controversia è competente il foro del luogo
        in cui ha sede il Fornitore.</p>

    @if (filled($noleggio->note))
        <h2 class="art">Note</h2>
        <div class="info-box">{!! nl2br(e($noleggio->note)) !!}</div>
    @endif

    <table class="firme" style="width:100%;">
        <tr>
            <td style="padding-top:22px;"><div class="riga-firma">Il Fornitore — {{ $azienda }}</div></td>
            <td style="width:8%;"></td>
            <td style="padding-top:22px;"><div class="riga-firma">Il Cliente, per accettazione</div></td>
        </tr>
    </table>

    <div class="footer-note">{{ $azienda }} &mdash; Documento generato il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
