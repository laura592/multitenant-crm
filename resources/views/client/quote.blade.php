@php
    use App\Models\QuoteResponse;
    use App\Support\DisplayName;

    // Stesso linguaggio visivo del PDF del preventivo (pdf/quote.blade.php e
    // pdf/partials/*-styles): intestazione con logo Alex + Franke, barre navy
    // #020F30, tabella righe, riquadro Riepilogo, niente angoli arrotondati.
    $company = $tenant?->legal_name ?: ($tenant?->name ?: config('app.name'));
    $logoUrl = $tenant?->logo_path && file_exists(public_path('storage/'.$tenant->logo_path))
        ? asset('storage/'.$tenant->logo_path)
        : asset('img/logo.svg');
    $frankeLogo = file_exists(public_path('img/franke_partner_logo.png')) ? asset('img/franke_partner_logo.png') : null;
    $customerName = DisplayName::titleCase($customer?->company_name) ?: DisplayName::titleCase($customer?->full_name);
    $money = fn ($value) => '€ '.number_format((float) $value, 2, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    $decided = $accepted || $allRejected;
    $multiple = $quotes->count() > 1;
    $justSent = session('client_response');

    // Il commerciale (Aziende > Contatto per i clienti), non il centralino.
    // Nessuna email in pagina: si scrive col modulo, e chi lo riceve si
    // decide in Impostazioni > Notifiche.
    $contactName = $tenant?->client_contact_name;
    $phone = $tenant?->client_contact_phone ?: $tenant?->phone;
    $telHref = $phone ? 'tel:'.preg_replace('/[^0-9+]/', '', $phone) : null;

    $openPanel = old('type') ? [
        QuoteResponse::TYPE_ACCEPTED => 'accetta',
        QuoteResponse::TYPE_REJECTED => 'rifiuta',
        QuoteResponse::TYPE_QUESTION => 'domanda',
        QuoteResponse::TYPE_CALLBACK => 'richiamata',
    ][old('type')] ?? null : ($justSent ? null : $initialAction);
    if ($decided && in_array($openPanel, ['accetta', 'rifiuta'], true)) {
        $openPanel = null;
    }
    $docLabel = $isGroup ? 'Offerta' : 'Preventivo';
    $action = route('client.quote.respond', ['token' => $token]);
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $docLabel }} {{ $document->number }} – {{ $company }}</title>
    <link rel="icon" href="{{ asset('img/favicon-cup.svg') }}">
    <style>
        :root {
            --navy: #020F30;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #e5e7eb;
            --soft: #f9fafb;
            --meta: #f0f4fa;
            --ok: #047857;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: #e9ecf1; color: var(--ink);
            font: 15px/1.5 "DejaVu Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .page { max-width: 820px; margin: 28px auto; background: #fff; padding: 36px 40px 28px; box-shadow: 0 1px 3px rgba(2, 15, 48, .08); }

        /* Intestazione come il PDF */
        .letterhead { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid var(--navy); padding-bottom: 12px; margin-bottom: 18px; }
        .logos img { display: block; }
        .logos .alex { max-height: 64px; max-width: 190px; }
        .logos .franke { margin-top: 8px; max-height: 40px; max-width: 130px; }
        .company { text-align: right; }
        .company-name { font-size: 17px; font-weight: bold; color: var(--navy); }
        .company-details { color: var(--muted); font-size: 12px; line-height: 1.55; }

        .doc-meta { display: flex; justify-content: space-between; background: var(--meta); padding: 7px 14px; margin-bottom: 12px; }
        .doc-meta .label { display: block; text-transform: uppercase; letter-spacing: .04em; font-size: 10px; color: var(--muted); }
        .doc-meta .value { font-size: 15px; font-weight: bold; color: var(--navy); }
        .doc-meta .to-right { text-align: right; }

        .section-title { background: var(--navy); color: #fff; padding: 6px 12px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; margin: 22px 0 0; }
        .info-box { background: var(--soft); border: 1px solid var(--line); border-top: none; padding: 12px 14px; }
        .customer-name { font-size: 15px; font-weight: bold; color: var(--navy); }

        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 14px; }
        table.items th { text-align: left; background: var(--navy); color: #fff; padding: 8px 8px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; }
        table.items td { border-bottom: 1px solid var(--line); padding: 9px 8px; vertical-align: top; }
        table.items tbody tr:nth-child(even) { background: var(--soft); }
        table.items .num { text-align: right; white-space: nowrap; }
        table.items .center { text-align: center; }
        table.items .opt td { color: #4b5563; font-size: 13px; }
        .sku { color: var(--muted); font-size: 12px; }

        .totals { width: 46%; margin: 14px 0 0 auto; border-collapse: collapse; font-size: 14px; }
        .totals th, .totals td { border: 1px solid var(--line); padding: 7px 12px; }
        .totals th { text-align: left; background: var(--soft); color: #374151; }
        .totals td { text-align: right; white-space: nowrap; }
        .totals .head th { background: var(--navy); color: #fff; }
        .totals .sub th, .totals .sub td { font-weight: bold; border-top: 2px solid var(--navy); }
        .totals .grand th, .totals .grand td { background: #f3f4f6; border-color: #d1d5db; font-weight: bold; color: #111827; }
        .totals .disc th, .totals .disc td { color: var(--ok); font-weight: bold; }

        .notes { margin-top: 18px; padding: 12px 14px; background: #fffbeb; border: 1px solid #fcd34d; border-left: 3px solid #f59e0b; font-size: 14px; }
        .notes h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .03em; color: #92400e; margin: 0 0 4px; }
        .pdf-link { display: inline-block; margin-top: 12px; font-size: 14px; color: var(--navy); }

        .quote-block + .quote-block { margin-top: 36px; padding-top: 24px; border-top: 1px dashed #cbd5e1; }
        .quote-block.dimmed { opacity: .5; }
        .stamp { display: inline-block; border: 2px solid var(--ok); color: var(--ok); font-weight: bold; text-transform: uppercase; letter-spacing: .05em; font-size: 12px; padding: 2px 10px; }

        /* Risposta */
        .answer { background: var(--soft); border: 1px solid var(--line); border-top: none; padding: 16px 14px; }
        .answer p { margin: 0 0 12px; }
        .buttons { display: flex; flex-wrap: wrap; gap: 10px; }
        .btn {
            appearance: none; cursor: pointer; font: inherit; font-weight: bold; font-size: 15px;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 20px; min-height: 48px; border: 1px solid var(--navy); text-decoration: none;
            background: #fff; color: var(--navy);
        }
        .btn:hover { background: var(--meta); }
        .btn-ok { background: var(--ok); border-color: var(--ok); color: #fff; }
        .btn-ok:hover { background: #065f46; }
        .btn-navy { background: var(--navy); color: #fff; }
        .btn-navy:hover { background: #0b1f52; }
        .btn-quiet { border-color: transparent; color: var(--muted); background: none; }
        .btn[aria-expanded="true"] { box-shadow: inset 0 -3px 0 var(--navy); }
        .btn-ok[aria-expanded="true"] { box-shadow: inset 0 -3px 0 #022c22; }
        .btn[disabled] { opacity: .6; cursor: progress; }
        .buttons .btn { flex: 1 1 180px; }
        .phone-line { margin: 14px 0 0; font-size: 14px; color: #374151; }
        .phone-line a { color: var(--navy); font-weight: bold; }
        .linklike { appearance: none; background: none; border: 0; padding: 0; font: inherit; color: var(--navy); text-decoration: underline; cursor: pointer; }

        .panel { display: none; border: 1px solid var(--line); border-top: 3px solid var(--navy); padding: 16px 14px; margin-top: 12px; background: #fff; }
        .panel.open { display: block; }
        .panel.accept { border-top-color: var(--ok); }
        .panel h2 { margin: 0 0 2px; font-size: 16px; color: var(--navy); }
        .panel .lead { margin: 0 0 6px; color: var(--muted); font-size: 14px; }
        label.field { display: block; margin-top: 12px; font-size: 13px; font-weight: bold; color: #374151; }
        label.field small { font-weight: normal; color: var(--muted); }
        input[type=text], input[type=tel], textarea {
            display: block; width: 100%; margin-top: 5px; padding: 10px 12px;
            font: inherit; font-size: 16px; color: var(--ink); border: 1px solid #cbd5e1; background: #fff; border-radius: 0;
        }
        input:focus, textarea:focus { outline: 2px solid var(--navy); outline-offset: -1px; }
        textarea { min-height: 100px; resize: vertical; }
        .options label { display: flex; align-items: center; gap: 10px; padding: 6px 0; font-size: 15px; cursor: pointer; }
        .options input { width: 18px; height: 18px; margin: 0; accent-color: var(--navy); flex: none; }
        .options em { font-style: normal; color: var(--muted); margin-left: auto; }
        .sig-box { position: relative; margin-top: 5px; }
        .sig-box canvas { display: block; width: 100%; height: 170px; touch-action: none; border: 1px solid #cbd5e1; background: #fff; cursor: crosshair; }
        .sig-line { position: absolute; left: 28px; right: 28px; bottom: 40px; border-bottom: 1px solid #d1d5db; pointer-events: none; }
        .sig-hint { position: absolute; left: 0; right: 0; bottom: 16px; text-align: center; color: #9ca3af; font-size: 13px; pointer-events: none; }
        .sig-tools { text-align: right; font-size: 13px; margin-top: 3px; }
        .sig-tools .linklike { color: var(--muted); }
        .consent { display: flex; gap: 10px; align-items: flex-start; margin-top: 14px; font-size: 14px; }
        .consent input { width: 18px; height: 18px; margin: 2px 0 0; flex: none; accent-color: var(--ok); }
        .actions { display: flex; gap: 8px; margin-top: 16px; }
        .err { color: #b91c1c; font-size: 13px; margin-top: 5px; }

        .note { margin-top: 14px; padding: 10px 14px; border: 1px solid #a7f3d0; border-left: 3px solid var(--ok); background: #ecfdf5; font-size: 14px; }
        .note.neutral { border-color: var(--line); border-left-color: var(--navy); background: var(--meta); }
        .note strong { display: block; color: var(--navy); }
        .messages { margin-top: 14px; }
        .msg { border-left: 3px solid #cbd5e1; background: #fff; padding: 8px 12px; margin-top: 8px; font-size: 14px; white-space: pre-line; }
        .msg small { display: block; color: var(--muted); font-size: 12px; white-space: normal; }

        .footer-note { margin-top: 26px; font-size: 11px; color: #9ca3af; text-align: center; }

        @media (max-width: 640px) {
            body { background: #fff; }
            .page { margin: 0; padding: 18px 14px 24px; box-shadow: none; }
            .letterhead { flex-direction: column; gap: 8px; }
            .company { text-align: left; }
            .company-details { display: none; }
            .logos { display: flex; align-items: center; justify-content: space-between; }
            .logos .alex { max-height: 50px; }
            .logos .franke { margin-top: 0; max-height: 30px; }
            .hide-sm { display: none; }
            .totals { width: 100%; }
            table.items { font-size: 13px; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="letterhead">
        <div class="logos">
            <img class="alex" src="{{ $logoUrl }}" alt="{{ $company }}">
            @if($frankeLogo)
                <img class="franke" src="{{ $frankeLogo }}" alt="Franke Approved Partner">
            @endif
        </div>
        <div class="company">
            <div class="company-name">{{ $company }}</div>
            <div class="company-details">
                @if($tenant?->pdfAddressLine()){{ $tenant->pdfAddressLine() }}<br>@endif
                @if($tenant?->pdfFiscalLine()){{ $tenant->pdfFiscalLine() }}@endif
            </div>
        </div>
    </header>

    @if($justSent)
        @php
            [$title, $text, $tone] = match ($justSent) {
                QuoteResponse::TYPE_ACCEPTED => ['Grazie per la fiducia.', 'Abbiamo ricevuto la sua conferma: le abbiamo inviato via email il preventivo firmato, e la contatteremo a breve per organizzare tutto.', ''],
                QuoteResponse::TYPE_REJECTED => ['Grazie per averci risposto.', 'Restiamo a disposizione, se in futuro dovesse servirle qualcosa.', 'neutral'],
                QuoteResponse::TYPE_QUESTION => ['Messaggio ricevuto.', 'Le risponderemo il prima possibile. Se vuole, può scriverci ancora qui sotto.', 'neutral'],
                default => ['La richiameremo al più presto.', 'Abbiamo avvisato il nostro ufficio commerciale.', 'neutral'],
            };
        @endphp
        <div class="note {{ $tone }}" role="status"><strong>{{ $title }}</strong>{{ $text }}</div>
    @endif

    @if($customerName)
        <div class="section-title" style="margin-top:14px;">Dati cliente</div>
        <div class="info-box"><div class="customer-name">{{ $customerName }}</div></div>
    @endif

    @foreach($quotes as $quote)
        @php
            $rows = $quote->quoteProducts->whereNull('parent_quote_product_id');
            $isRental = $quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee;
            $isChosen = $accepted && $accepted->is($quote);
            $gross = $quote->quoteProducts->sum(fn ($p) => $p->quantity * $p->price);
            $net = $quote->quoteProducts->sum('total');
            $scontoGenerale = $net * ((float) $quote->discount / 100);
            $scontoExtra = ($net - $scontoGenerale) * ((float) ($quote->extra_discount ?? 0) / 100);
        @endphp
        <section class="quote-block {{ $accepted && ! $isChosen ? 'dimmed' : '' }}" style="margin-top:18px;">
            <div class="doc-meta">
                <div>
                    <span class="label">{{ $multiple ? 'Soluzione '.$loop->iteration : 'Preventivo' }}</span>
                    <span class="value">{{ $quote->number }}</span>
                </div>
                <div class="to-right">
                    @if($isChosen)
                        <span class="stamp">Accettato</span>
                    @else
                        <span class="label">Data</span>
                        <span class="value">{{ $quote->date?->format('d/m/Y') }}</span>
                    @endif
                </div>
            </div>

            @if($quote->paymentMethodRelation?->name || $isRental)
                <div class="section-title" style="margin-top:0;">Condizioni di pagamento</div>
                <div class="info-box">
                    @if($quote->paymentMethodRelation?->name)<div>{{ $quote->paymentMethodRelation->name }}</div>@endif
                    @if($isRental)
                        <div style="margin-top:4px;">Pagamento rateale tramite Grenke: <strong>{{ $money($quote->rental_monthly_fee) }} + IVA al mese</strong> per <strong>{{ $quote->rental_months }} mesi</strong>.</div>
                    @endif
                </div>
            @endif

            <table class="items">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th class="center">Qtà</th>
                        <th class="num hide-sm">Prezzo unit.</th>
                        <th class="num hide-sm">Sconto</th>
                        <th class="num">Imponibile</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td><strong>{{ $row->product?->name ?? 'Articolo' }}</strong>@if($row->product?->sku)<br><span class="sku">SKU: {{ $row->product->sku }}</span>@endif</td>
                            <td class="center">{{ $qty($row->quantity) }}</td>
                            <td class="num hide-sm">{{ $money($row->price) }}</td>
                            <td class="num hide-sm">{{ $row->discount ?: 0 }}%</td>
                            <td class="num">{{ $money($row->total) }}</td>
                        </tr>
                        @foreach($row->options ?? [] as $option)
                            <tr class="opt">
                                <td>↳ {{ $option->product?->name }}</td>
                                <td class="center">{{ $qty($option->quantity) }}</td>
                                <td class="num hide-sm">{{ $money($option->price) }}</td>
                                <td class="num hide-sm">{{ $option->discount ?: 0 }}%</td>
                                <td class="num">{{ $money($option->total) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>

            <table class="totals">
                <tr class="head"><th colspan="2">Riepilogo</th></tr>
                @if($gross - $net > 0.004)
                    <tr><th>Totale lordo</th><td>{{ $money($gross) }}</td></tr>
                    <tr class="disc"><th>Sconto prodotti</th><td>-{{ $money($gross - $net) }}</td></tr>
                @endif
                @if($quote->discount > 0)
                    <tr class="disc"><th>Sconto generale {{ number_format($quote->discount, 2, ',', '.') }}%</th><td>-{{ $money($scontoGenerale) }}</td></tr>
                @endif
                @if(($quote->extra_discount ?? 0) > 0)
                    <tr class="disc"><th>Sconto extra {{ number_format($quote->extra_discount, 2, ',', '.') }}%</th><td>-{{ $money($scontoExtra) }}</td></tr>
                @endif
                <tr class="sub"><th>Imponibile</th><td>{{ $money($quote->subtotal) }}</td></tr>
                <tr><th>IVA</th><td>{{ $money($quote->tax_total) }}</td></tr>
                <tr class="grand"><th>Totale IVA inclusa</th><td>{{ $money($quote->total) }}</td></tr>
            </table>

            @if($quote->notes)
                <div class="notes"><h2>Descrizione attrezzatura</h2>{!! $quote->notes !!}</div>
            @endif

            <a class="pdf-link" href="{{ route('client.quote.pdf', ['token' => $token, 'quoteId' => $quote->id]) }}" target="_blank" rel="noopener">Scarica il PDF{{ $isChosen ? ' firmato' : '' }}</a>
        </section>
    @endforeach

    <div class="section-title" id="rispondi" style="margin-top:30px;">La sua risposta</div>
    <div class="answer">
        @if($accepted)
            <p><strong>Preventivo{{ $multiple ? ' '.$accepted->number : '' }} accettato</strong>@if($acceptance) da {{ $acceptance->signer_name }} il {{ $acceptance->created_at->timezone(config('app.timezone'))->format('d/m/Y') }}@endif. Grazie! Per qualsiasi domanda può scriverci qui.</p>
        @elseif($allRejected)
            <p>Ci ha già risposto, grazie. Se ha cambiato idea o preferisce una proposta diversa, ci scriva: la rivediamo volentieri insieme.</p>
        @else
            <p>{{ $multiple ? 'Può confermare la soluzione che preferisce, farci tutte le domande che vuole, oppure dirci che per ora non le interessa.' : 'Può confermare il preventivo, farci tutte le domande che vuole, oppure dirci che per ora non le interessa.' }}</p>
        @endif

        <div class="buttons">
            @unless($decided)
                <button type="button" class="btn btn-ok" data-open="accetta" aria-expanded="false">✓ {{ $multiple ? 'Scelgo e firmo' : 'Accetto e firmo' }}</button>
            @endunless
            <button type="button" class="btn" data-open="domanda" aria-expanded="false">Ho una domanda</button>
            @unless($decided)
                <button type="button" class="btn" data-open="rifiuta" aria-expanded="false">Non mi interessa</button>
            @endunless
        </div>

        <p class="phone-line">
            @if($telHref)Per informazioni: {{ $contactName ? $contactName.' – ' : '' }}<a href="{{ $telHref }}">{{ $phone }}</a> · @endif
            <button type="button" class="linklike" data-open="richiamata">Chiedi di essere richiamato</button>
        </p>

        @if($questions->isNotEmpty())
            <div class="messages">
                <strong style="font-size:13px; color:#374151;">I suoi messaggi</strong>
                @foreach($questions as $q)
                    <div class="msg"><small>{{ $q->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</small>{{ $q->message }}</div>
                @endforeach
            </div>
        @endif

        @unless($decided)
        <section class="panel accept" data-panel="accetta">
            <form method="POST" action="{{ $action }}" data-form="accetta" novalidate>
                @csrf
                <input type="hidden" name="type" value="{{ QuoteResponse::TYPE_ACCEPTED }}">
                <input type="hidden" name="signature" value="">
                <h2>Per accettazione</h2>
                <p class="lead">La sua firma verrà riportata sul preventivo, che le invieremo via email.</p>
                @if(old('type') === QuoteResponse::TYPE_ACCEPTED && $errors->any())
                    <p class="err">{{ $errors->first() }}</p>
                @endif

                @if($multiple)
                    <div class="options">
                        @foreach($openQuotes as $quote)
                            <label>
                                <input type="radio" name="quote_id" value="{{ $quote->id }}" @checked(old('quote_id') === $quote->id)>
                                Soluzione {{ $quotes->search(fn ($q) => $q->is($quote)) + 1 }} – {{ $quote->number }}
                                <em>{{ $money($quote->subtotal) }} + IVA</em>
                            </label>
                        @endforeach
                    </div>
                @else
                    <input type="hidden" name="quote_id" value="{{ $quotes->first()?->id }}">
                @endif

                <label class="field">Nome e cognome
                    <input type="text" name="signer_name" value="{{ old('signer_name') }}" autocomplete="name" maxlength="120">
                </label>
                <label class="field" style="margin-bottom:0;">Firma</label>
                <div class="sig-box">
                    <canvas data-signature aria-label="Riquadro per la firma"></canvas>
                    <div class="sig-line"></div>
                    <div class="sig-hint" data-sig-hint>Firmi qui con il dito o con il mouse</div>
                </div>
                <div class="sig-tools"><button type="button" class="linklike" data-sig-clear>Cancella</button></div>
                <div class="err" data-sig-error hidden>Manca la firma.</div>

                <label class="consent">
                    <input type="checkbox" name="consent" value="1" @checked(old('consent'))>
                    <span>Accetto il preventivo{{ $multiple ? ' della soluzione scelta' : '' }} alle condizioni indicate.</span>
                </label>
                <div class="err" data-consent-error hidden>Serve questa conferma per procedere.</div>

                <div class="actions">
                    <button type="submit" class="btn btn-ok">Firmo e accetto</button>
                    <button type="button" class="btn btn-quiet" data-close>Annulla</button>
                </div>
            </form>
        </section>

        <section class="panel" data-panel="rifiuta">
            <form method="POST" action="{{ $action }}" data-form="rifiuta">
                @csrf
                <input type="hidden" name="type" value="{{ QuoteResponse::TYPE_REJECTED }}">
                <h2>Grazie di avercelo detto</h2>
                <p class="lead">Se le va, ci dica il motivo: ci aiuta a fare meglio. Non è obbligatorio.</p>
                @if(old('type') === QuoteResponse::TYPE_REJECTED && $errors->any())
                    <p class="err">{{ $errors->first() }}</p>
                @endif
                <div class="options">
                    @foreach(QuoteResponse::reasonLabels() as $key => $label)
                        <label><input type="radio" name="reason" value="{{ $key }}" @checked(old('reason') === $key)> {{ $label }}</label>
                    @endforeach
                </div>
                <label class="field">Vuole aggiungere qualcosa? <small>(facoltativo)</small>
                    <textarea name="message" maxlength="3000">{{ old('message') }}</textarea>
                </label>
                <div class="actions">
                    <button type="submit" class="btn btn-navy">Invia</button>
                    <button type="button" class="btn btn-quiet" data-close>Annulla</button>
                </div>
            </form>
        </section>
        @endunless

        <section class="panel" data-panel="domanda">
            <form method="POST" action="{{ $action }}" data-form="domanda">
                @csrf
                <input type="hidden" name="type" value="{{ QuoteResponse::TYPE_QUESTION }}">
                <h2>{{ $questions->isNotEmpty() ? 'Un\'altra domanda' : 'La sua domanda' }}</h2>
                <p class="lead">Chieda pure tutto quello che vuole, anche una modifica al preventivo. Le rispondiamo via email.</p>
                @if(old('type') === QuoteResponse::TYPE_QUESTION && $errors->any())
                    <p class="err">{{ $errors->first() }}</p>
                @endif
                <label class="field">Messaggio
                    <textarea name="message" maxlength="3000" required>{{ old('message') }}</textarea>
                </label>
                <div class="actions">
                    <button type="submit" class="btn btn-navy">Invia</button>
                    <button type="button" class="btn btn-quiet" data-close>Annulla</button>
                </div>
            </form>
        </section>

        <section class="panel" data-panel="richiamata">
            <form method="POST" action="{{ $action }}" data-form="richiamata">
                @csrf
                <input type="hidden" name="type" value="{{ QuoteResponse::TYPE_CALLBACK }}">
                <h2>La richiamiamo noi</h2>
                @if(old('type') === QuoteResponse::TYPE_CALLBACK && $errors->any())
                    <p class="err">{{ $errors->first() }}</p>
                @endif
                <label class="field">Numero di telefono
                    <input type="tel" name="phone" value="{{ old('phone', $knownPhone) }}" autocomplete="tel" inputmode="tel" required>
                </label>
                <div class="options" style="margin-top:10px;">
                    @foreach(QuoteResponse::preferredTimeLabels() as $key => $label)
                        <label><input type="radio" name="preferred_time" value="{{ $key }}" @checked(old('preferred_time', 'indifferente') === $key)> {{ $label }}</label>
                    @endforeach
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-navy">Invia</button>
                    <button type="button" class="btn btn-quiet" data-close>Annulla</button>
                </div>
            </form>
        </section>
    </div>

    <div class="footer-note">{{ $company }} &mdash; Questo documento non costituisce fattura</div>
</main>

<script>
(function () {
    var panels = document.querySelectorAll('[data-panel]');
    var toggles = document.querySelectorAll('.buttons [data-open]');

    function open(name) {
        panels.forEach(function (p) { p.classList.toggle('open', p.dataset.panel === name); });
        toggles.forEach(function (t) { t.setAttribute('aria-expanded', t.dataset.open === name ? 'true' : 'false'); });
        var panel = document.querySelector('[data-panel="' + name + '"]');
        if (!panel) return;
        if (name === 'accetta') setupSignature();
        setTimeout(function () { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 30);
    }

    document.querySelectorAll('[data-open]').forEach(function (el) {
        el.addEventListener('click', function () { open(el.dataset.open); });
    });
    document.querySelectorAll('[data-close]').forEach(function (el) {
        el.addEventListener('click', function () {
            panels.forEach(function (p) { p.classList.remove('open'); });
            toggles.forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
        });
    });

    // Firma: canvas alla risoluzione reale dello schermo (nitida anche su
    // retina), pointer events per dito, penna e mouse insieme.
    var canvas = document.querySelector('[data-signature]');
    var ready = false, drawn = false, drawing = false, ctx, last;

    function setupSignature() {
        if (!canvas || ready) return;
        ready = true;
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = ctx.fillStyle = '#020F30';

        function point(e) {
            var r = canvas.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }
        canvas.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            canvas.setPointerCapture(e.pointerId);
            drawing = true;
            last = point(e);
            ctx.beginPath();
            ctx.arc(last.x, last.y, 1, 0, Math.PI * 2);
            ctx.fill();
            drawn = true;
            document.querySelector('[data-sig-hint]').style.display = 'none';
            document.querySelector('[data-sig-error]').hidden = true;
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!drawing) return;
            e.preventDefault();
            var p = point(e);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
            canvas.addEventListener(ev, function () { drawing = false; });
        });
    }

    var clear = document.querySelector('[data-sig-clear]');
    if (clear) clear.addEventListener('click', function () {
        if (!ctx) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawn = false;
        document.querySelector('[data-sig-hint]').style.display = '';
    });

    var acceptForm = document.querySelector('[data-form="accetta"]');
    if (acceptForm) acceptForm.addEventListener('submit', function (e) {
        var ok = true;
        var name = acceptForm.querySelector('[name="signer_name"]');
        var consent = acceptForm.querySelector('[name="consent"]');
        if (acceptForm.querySelectorAll('input[type=radio][name="quote_id"]').length && !acceptForm.querySelector('input[name="quote_id"]:checked')) {
            ok = false; alert('Scelga quale soluzione preferisce.');
        }
        if (!name.value.trim()) { ok = false; name.focus(); }
        document.querySelector('[data-sig-error]').hidden = drawn;
        if (!drawn) ok = false;
        document.querySelector('[data-consent-error]').hidden = consent.checked;
        if (!consent.checked) ok = false;
        if (!ok) { e.preventDefault(); return; }
        acceptForm.querySelector('[name="signature"]').value = canvas.toDataURL('image/png');
    });

    // Un solo invio per form: niente risposte doppie da un doppio tocco.
    document.querySelectorAll('form[data-form]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            var btn = form.querySelector('button[type=submit]');
            setTimeout(function () { btn.disabled = true; btn.textContent = 'Invio…'; }, 0);
        });
    });

    var initial = @json($openPanel);
    if (initial) open(initial);
})();
</script>
</body>
</html>
