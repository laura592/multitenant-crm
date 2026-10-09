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
        // Le condizioni di pagamento si leggono dalle stesse etichette del
        // gestionale: una dicitura sola, cosi' non si discute su cosa si era
        // pattuito.
        $periodicita = \App\Models\Noleggio::periodicitaLabels()[$noleggio->periodicita_fatturazione] ?? 'mensile anticipata';
        $modalita = \App\Models\Noleggio::modalitaPagamentoLabels()[$noleggio->modalita_pagamento] ?? 'bonifico bancario';
        $termini = \App\Models\Noleggio::terminiPagamentoLabels()[$noleggio->termini_pagamento] ?? '30 giorni data fattura';
        // Nel contratto vanno le QUANTITA', non i prezzi: il cliente deve
        // sapere cosa gli spetta, non come e' composto il nostro margine. Il
        // dettaglio economico resta nel CRM.
        // Le quantita' si scrivono ANNUE e intere. Il calcolo interno e'
        // mensile e produce numeri come "4,33 pz" o "0,77 × 250 g": su un
        // contratto non vogliono dire niente, nessuno consegna un terzo di
        // spruzzino. Moltiplicate per dodici tornano a essere unita' vere —
        // 52 spruzzini, 18 flaconi — e il Cliente legge un impegno che si
        // puo' davvero onorare (Laura, 06/10/2026).
        //
        // Quando l'unita' e' una confezione con una pezzatura ("500 g",
        // "250 g") ci vuole il "per"; quando e' una misura o un pezzo, no.
        // Su un contratto piu' corto dell'anno -- un noleggio di stagione --
        // promettere "365 pastiglie all'anno" e' falso: il cliente la macchina
        // ce l'ha sei mesi. Le quantita' si riportano alla durata vera
        // (Laura, 09/10/2026).
        $mesiFornitura = min(12, max(1, $mesi));
        // Composta qui e non con un @if in mezzo alla frase: Blade non
        // compila una direttiva attaccata a una lettera ("contratto@else"),
        // e finisce stampata com'e'. Ci sono gia' cascato due volte.
        $periodoQuantita = $mesiFornitura < 12
            ? "complessivi per i {$mesiFornitura} mesi di contratto"
            : 'annui';

        $quantita = function ($r) use ($mesiFornitura) {
            // La quantita' salvata e' annua: e' cosi' che si scrive nel
            // prospetto. Qui si riporta alla durata del contratto.
            $annua = (float) $r->quantita / 12 * $mesiFornitura;

            // Mai zero: una fornitura prevista ma minima si arrotonda per
            // eccesso all'unita', altrimenti il contratto la nega.
            $n = $annua > 0 ? max(1, (int) round($annua)) : 0;

            $unita = trim((string) $r->unita);
            $confezione = (bool) preg_match('/\d/', $unita);

            // "900 × 1 kg" si scrive "900 kg": il moltiplicatore serve solo
            // dove la confezione ha una pezzatura propria.
            if (preg_match('/^1\s+(.+)$/u', $unita, $m)) {
                $unita = $m[1];
                $confezione = false;
            }

            return $r->voce.' '.number_format($n, 0, ',', '.').($confezione ? ' × ' : ' ').$unita;
        };
    @endphp

    {{-- Il numero sul documento: e' cosi' che il contratto si richiama in
         una mail o al telefono, e lo stesso numero si cerca nel gestionale. --}}
    {{-- Il numero si compone nel PHP e non con un @if attaccato al titolo:
         Blade non compila una direttiva appiccicata a una lettera
         ("OPERATIVO@if"), compila solo l'@endif, e la vista va in errore di
         sintassi. Ci sono gia' cascato stamattina. --}}
    @php
        $titolo = 'CONTRATTO DI NOLEGGIO OPERATIVO'
            .(filled($noleggio->number) ? ' <span style="font-size:14px;">N. '.e($noleggio->number).'</span>' : '');
    @endphp
    <h1 class="titolo">{!! $titolo !!}</h1>
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
        all'assistenza tecnica e alle forniture di consumo previste dall'art. 5.</p>
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
    <p>Il canone è comprensivo di quanto previsto dall'art. 5 e si intende dovuto per l'intera durata del
        contratto.</p>
    {{-- Il presupposto del canone sta qui, nell'articolo del canone, e non
         in fondo alle quantita': e' il punto in cui il cliente valuta la
         cifra, ed e' cio' che permette di rivederla se il servizio cambia
         davvero, invece di discuterne a memoria (Laura, 07/10/2026). --}}
    @if (filled($noleggio->base_consumo))
        <p>Il canone è determinato su un <strong>utilizzo stimato di {{ $noleggio->base_consumo }}</strong>.
            Alla fine di ogni anno di contratto il Fornitore rileva i consumi effettivi e procede al
            <strong>conguaglio</strong>: le quantità eccedenti quelle indicate all'art. 5 sono fatturate
            a consumo, e il canone dell'anno successivo è adeguato ai consumi rilevati.</p>
    @endif

    {{-- Due rischi diversi, due clausole. L'ISTAT copre l'inflazione
         generale, che e' quella dei costi di struttura; il caffe' verde si
         muove su un mercato suo e un anno di FOI al 2% non basta se la
         materia prima fa +30%. Simmetrica in aumento e in diminuzione: una
         clausola che prevede solo aumenti si contesta piu' facilmente
         (Laura, 07/10/2026). --}}
    <p>A partire dal secondo anno, a ogni anniversario della decorrenza il canone è
        <strong>adeguato alla variazione dell'indice ISTAT</strong> dei prezzi al consumo per le famiglie di
        operai e impiegati (FOI, al netto dei tabacchi) rilevata nei dodici mesi precedenti.</p>
    <p>Indipendentemente dall'indice di cui sopra, la parte di canone relativa alle forniture di consumo
        indicate all'art. 5 è adeguata alle <strong>variazioni dei prezzi di listino</strong> dei prodotti
        compresi, <strong>in aumento e in diminuzione</strong>, comunicate con almeno trenta giorni di
        preavviso.</p>

    <h3 class="art">Art. 4 – Fatturazione e pagamento</h3>
    <p>Il canone è fatturato con periodicità <strong>{{ $periodicita }}</strong> e pagato a mezzo
        <strong>{{ $modalita }}</strong> a <strong>{{ $termini }}</strong>, sulle coordinate indicate in fattura.
        Il canone decorre dalla data indicata all'art. 2.</p>
    {{-- Gli interessi di mora valgono per legge anche senza scriverli: messi
         nero su bianco servono a non doverli spiegare la prima volta che si
         sollecita. --}}
    <p>In caso di ritardato pagamento decorrono gli interessi di mora nella misura prevista dal
        D.Lgs. 231/2002, fatto salvo il diritto del Fornitore di sospendere le forniture di consumo di cui
        all'art. 5 fino al saldo.</p>

    <h3 class="art">Art. 5 – Cosa è compreso nel canone</h3>
    <ul>
        <li>La <strong>disponibilità dell'attrezzatura</strong> indicata all'art. 1 per tutta la durata del contratto.</li>
        <li>La <strong>consegna, l'installazione e l'allacciamento</strong> dell'attrezzatura.</li>
        <li>L'<strong>assistenza tecnica full-service</strong>: manutenzioni programmate, interventi su chiamata,
            fornitura e sostituzione dei ricambi, manodopera e trasferte del personale tecnico, supporto telefonico.</li>
        @foreach ($perGruppo as $gruppo => $righe)
            <li>La fornitura di <strong>{{ mb_strtolower(\App\Models\NoleggioFornitura::gruppiLabels()[$gruppo] ?? $gruppo) }}</strong>,
                nei quantitativi {{ $periodoQuantita }}
                di @foreach ($righe as $r){{ $quantita($r) }}@if(! $loop->last); @endif@endforeach.</li>
        @endforeach
        @if ($forniture->isEmpty())
            <li>Le forniture di consumo nei quantitativi concordati fra le parti.</li>
        @endif
    </ul>

    @if (filled($noleggio->base_consumo))
        <p>I quantitativi sopra indicati sono determinati sull'utilizzo stimato indicato all'art. 3.</p>
    @endif

    <h3 class="art">Art. 6 – Cosa non è compreso</h3>
    <ul>
        <li>Le <strong>predisposizioni</strong> necessarie all'installazione — punto acqua, scarico e alimentazione
            elettrica — che restano a carico del Cliente e devono essere realizzate prima dell'intervento.</li>
        <li>I <strong>quantitativi di fornitura eccedenti</strong> quelli indicati all'art. 5, che sono
            fatturati a consumo con il conguaglio annuale di cui all'art. 3.</li>
        <li>Il <strong>latte</strong> e gli altri ingredienti non espressamente elencati all'art. 5.</li>
        {{-- Detto per nome: "ingredienti" non copre un bicchiere, e dare per
             scontato che i consumabili siano compresi e' esattamente il
             genere di equivoco che si scopre alla prima consegna. --}}
        @if (! $perGruppo->has(\App\Models\NoleggioFornitura::GRUPPO_CONSUMABILI))
            <li>I <strong>consumabili</strong> — bicchieri, palette, zucchero e simili — che restano a carico
                del Cliente.</li>
        @endif
        <li>Le riparazioni rese necessarie da <strong>uso improprio, negligenza, manomissioni</strong> o interventi
            eseguiti da personale non autorizzato dal Fornitore.</li>
        <li>I <strong>consumi di energia elettrica e acqua</strong> e gli oneri di legge.</li>
    </ul>

    <h3 class="art">Art. 7 – Proprietà e restituzione</h3>
    <p>L'attrezzatura resta di <strong>esclusiva proprietà del Fornitore</strong> per tutta la durata del contratto.
        Il Cliente non può cederla, darla in uso a terzi, spostarla in altra sede né sottoporla a modifiche senza
        autorizzazione scritta. Alla scadenza l'attrezzatura va restituita nello stato in cui è stata consegnata,
        salvo il normale deperimento d'uso.</p>

    <h3 class="art">Art. 8 – Obblighi del Cliente</h3>
    <p>Il Cliente si impegna a utilizzare l'attrezzatura secondo le istruzioni ricevute, a eseguire le operazioni
        quotidiane di pulizia previste dal costruttore, a segnalare tempestivamente malfunzionamenti e a consentire
        l'accesso al personale tecnico per gli interventi previsti.</p>

    <h3 class="art">Art. 9 – Recesso anticipato</h3>
    <p>In caso di recesso del Cliente prima della scadenza restano dovuti i canoni residui, salvo diverso accordo
        scritto fra le parti.</p>

    <h3 class="art">Art. 10 – Legge applicabile e foro competente</h3>
    {{-- Foro indicato per nome e non "quello della sede del Fornitore": una
         clausola che si legge senza dover sapere dove ha sede chi la scrive. --}}
    <p>Il presente contratto è regolato dalla legge italiana. Per ogni controversia che dovesse insorgere in
        relazione al presente contratto è competente in via esclusiva il <strong>Foro di Venezia</strong>.</p>

    {{-- Le note del noleggio NON si stampano: sono appunti interni — costo
         d'acquisto, sconto fornitore ipotizzato, "bozza di studio" — e sul
         contratto del cliente non ci devono finire. Si leggono nella scheda
         del CRM. --}}

    <table class="firme" style="width:100%;">
        <tr>
            <td style="padding-top:22px;"><div class="riga-firma">Il Fornitore — {{ $azienda }}</div></td>
            <td style="width:8%;"></td>
            <td style="padding-top:22px;"><div class="riga-firma">Il Cliente, per accettazione</div></td>
        </tr>
    </table>


</body>
</html>
