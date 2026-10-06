<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Contratto di noleggio operativo — {{ $noleggio->customer?->company_name }}</title>
    <style>
        /* Impostazione dei contratti Alex (Full-Service, Easy-Service):
           testo con grazie, titoli neri in grassetto senza grazie con una
           riga sopra, nessun colore e nessun riquadro. Il PDF non deve
           sembrare una pagina web: deve sembrare un contratto. */
        body { font-family: "Times New Roman", Times, serif; font-size: 11.5px; color: #000; line-height: 1.5; }

        /* Intestazione dei contratti: vedi components/pdf-letterhead-contratto */
        .letterhead-contratto { width: 100%; margin-bottom: 18px; }
        .letterhead-contratto td { border: none; padding: 0; vertical-align: top; }
        .letterhead-contratto .logo { width: 200px; }
        .letterhead-contratto .alex-logo { display: block; max-height: 56px; max-width: 170px; }
        .letterhead-contratto .franke-logo { display: block; margin-top: 6px; max-height: 32px; max-width: 120px; }
        .letterhead-contratto .dati { text-align: right; font-size: 8.5px; line-height: 1.45; }
        .letterhead-contratto .dati .nome { font-weight: bold; font-size: 10px; }

        h1.titolo { font-family: Arial, Helvetica, sans-serif; font-size: 18px; margin: 20px 0 4px; }
        h2.sottotitolo { font-family: Arial, Helvetica, sans-serif; font-size: 13px; margin: 0 0 16px; line-height: 1.35; }
        h3.art {
            font-family: Arial, Helvetica, sans-serif; font-size: 13px;
            margin: 16px 0 6px; padding-top: 6px; border-top: 1px solid #000;
        }
        p { margin: 0 0 7px; text-align: justify; }
        ul { margin: 2px 0 7px 0; padding-left: 26px; }
        ul li { margin-bottom: 4px; }
        .dati { margin: 0 0 7px 14px; }
        /* Le firme non si spezzano a fine pagina. */
        .firme { page-break-inside: avoid; margin-top: 30px; width: 100%; }
        .firme td { padding-top: 30px; font-size: 9.5px; }
        .riga-firma { border-top: 1px solid #000; padding-top: 4px; width: 80%; }
        .piede { margin-top: 18px; font-size: 8.5px; color: #555; }
    </style>
</head>
<body>
    <x-pdf-letterhead-contratto :tenant="$tenant" />

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
    <h2 class="sottotitolo">Fornitura in uso di attrezzatura professionale per la somministrazione di caffè,
        con assistenza tecnica e forniture di consumo comprese nel canone</h2>

    <p style="margin-top:14px;"><strong>Tra</strong></p>
    <div class="dati">
        {{ $azienda }}@if($tenant?->pdfAddressLine()), {{ $tenant->pdfAddressLine() }}@endif — di seguito «il Fornitore»<br><br>
    </div>
    <p><strong>e</strong></p>
    <div class="dati">
        {{ $noleggio->customer?->company_name ?: trim($noleggio->customer?->first_name.' '.$noleggio->customer?->last_name) }}@if($noleggio->customer?->street), {{ $noleggio->customer->street }}@endif@if($noleggio->customer?->postal_code), {{ $noleggio->customer->postal_code }} {{ $noleggio->customer->city }}@if(filled($noleggio->customer?->province)) ({{ $noleggio->customer->province }})@endif
        @endif@if($noleggio->customer?->vat_number)<br>P. IVA {{ $noleggio->customer->vat_number }}@endif — di seguito «il Cliente»
    </div>

    <h3 class="art">Art. 1 – Oggetto</h3>
    <p>Il Fornitore concede al Cliente, in noleggio operativo, l'attrezzatura di seguito indicata, unitamente
        all'assistenza tecnica e alle forniture di consumo previste dall'art. 4.</p>
    <div class="dati"><strong>{{ $noleggio->descrizione }}</strong>@if($noleggio->machineUnit)<br>Matricola {{ $noleggio->machineUnit->serial_number }}@endif</div>

    <h3 class="art">Art. 2 – Durata e decorrenza</h3>
    <p>Il contratto ha durata di <strong>{{ $mesi }} mesi</strong>
        @if($noleggio->data_inizio) con decorrenza dal {{ $noleggio->data_inizio->format('d/m/Y') }}@endif.
        Alla scadenza si intende concluso, salvo rinnovo concordato per iscritto fra le parti.</p>

    <h3 class="art">Art. 3 – Canone</h3>
    <p>Il canone è stabilito in <strong>{{ $eur($noleggio->canone) }} al mese, IVA esclusa</strong>.</p>
    {{-- Niente totale su tutta la durata: il canone mensile e' il numero che
         il cliente deve valutare, la somma dei cinque anni spaventa e non
         aggiunge nulla a cio' che il contratto stabilisce. --}}
    <p>Il canone è comprensivo di quanto previsto dall'art. 4 e si intende dovuto per l'intera durata del
        contratto.</p>

    <h3 class="art">Art. 4 – Cosa è compreso nel canone</h3>
    <ul>
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

    <h3 class="art">Art. 5 – Cosa non è compreso</h3>
    <ul>
        <li>Le <strong>predisposizioni</strong> necessarie all'installazione — punto acqua, scarico e alimentazione
            elettrica — che restano a carico del Cliente e devono essere realizzate prima dell'intervento.</li>
        <li>I <strong>quantitativi di fornitura eccedenti</strong> quelli indicati all'art. 4, che sono fatturati a consumo.</li>
        <li>Il <strong>latte</strong> e gli altri ingredienti non espressamente elencati all'art. 4.</li>
        <li>Le riparazioni rese necessarie da <strong>uso improprio, negligenza, manomissioni</strong> o interventi
            eseguiti da personale non autorizzato dal Fornitore.</li>
        <li>I <strong>consumi di energia elettrica e acqua</strong> e gli oneri di legge.</li>
    </ul>

    <h3 class="art">Art. 6 – Proprietà e restituzione</h3>
    <p>L'attrezzatura resta di <strong>esclusiva proprietà del Fornitore</strong> per tutta la durata del contratto.
        Il Cliente non può cederla, darla in uso a terzi, spostarla in altra sede né sottoporla a modifiche senza
        autorizzazione scritta. Alla scadenza l'attrezzatura va restituita nello stato in cui è stata consegnata,
        salvo il normale deperimento d'uso.</p>

    <h3 class="art">Art. 7 – Obblighi del Cliente</h3>
    <p>Il Cliente si impegna a utilizzare l'attrezzatura secondo le istruzioni ricevute, a eseguire le operazioni
        quotidiane di pulizia previste dal costruttore, a segnalare tempestivamente malfunzionamenti e a consentire
        l'accesso al personale tecnico per gli interventi previsti.</p>

    <h3 class="art">Art. 8 – Recesso anticipato</h3>
    <p>In caso di recesso del Cliente prima della scadenza restano dovuti i canoni residui, salvo diverso accordo
        scritto fra le parti.</p>

    <h3 class="art">Art. 9 – Legge applicabile e foro competente</h3>
    {{-- Foro indicato per nome e non "quello della sede del Fornitore": una
         clausola che si legge senza dover sapere dove ha sede chi la scrive. --}}
    <p>Il presente contratto è regolato dalla legge italiana. Per ogni controversia che dovesse insorgere in
        relazione al presente contratto è competente in via esclusiva il <strong>Foro di Venezia</strong>.</p>

    @if (filled($noleggio->note))
        <h3 class="art">Note</h3>
        <div class="dati">{!! nl2br(e($noleggio->note)) !!}</div>
    @endif

    <table class="firme" style="width:100%;">
        <tr>
            <td style="padding-top:22px;"><div class="riga-firma">Il Fornitore — {{ $azienda }}</div></td>
            <td style="width:8%;"></td>
            <td style="padding-top:22px;"><div class="riga-firma">Il Cliente, per accettazione</div></td>
        </tr>
    </table>


</body>
</html>
