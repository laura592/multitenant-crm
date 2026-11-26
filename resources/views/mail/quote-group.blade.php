<x-mail::message>
@php
	$tenant = $group->tenant ?: $group->customer?->tenant;
	$resolvedSubject = trim((string) ($subjectText ?? "Offerta {$group->number}"));
	$renderedBody = trim((string) ($emailBody ?? ''));
	// Il corpo ora arriva in HTML dal rich editor, ma le offerte inviate
	// prima hanno il testo salvato a righe: senza tag si continua a
	// convertire gli a capo, altrimenti si stampa il markup cosi' com'e'.
	$bodyHtml = $renderedBody === ''
		? ''
		: ($renderedBody !== strip_tags($renderedBody) ? $renderedBody : nl2br(e($renderedBody)));
	// Si saluta il cliente, non chi paga (vedi QuoteResource).
	$recipient = $group->customer;
	$customerName = \App\Support\DisplayName::titleCase($recipient?->company_name) ?: \App\Support\DisplayName::titleCase($recipient?->full_name);
@endphp

<x-mail.hero
	:kicker="'Offerta '.$group->number"
	:title="$resolvedSubject"
	:subtitle="'Destinatario: '.$customerName"
/>

<x-mail.box>
{!! \App\Support\HtmlSicuro::filtra($bodyHtml) !!}
</x-mail.box>

@if($quotes->isNotEmpty())
@php
	// Che cosa distingue una soluzione dall'altra: la MACCHINA, non il
	// numero di preventivo. Prima questa tabella diceva "PRV-2026-0046 /
	// 16.490" e "PRV-2026-0047 / 17.646", cioe' due codici e due prezzi: il
	// cliente non poteva capire perche' uno costasse di piu' (Laura,
	// 26/11/2026 -- "si capisce poco").
	//
	// Si nominano macchine e unita' affiancate (il frigo-latte, il secondo
	// gruppo) e si tacciono opzioni e accessori: su due alternative dello
	// stesso modello quelli sono quasi sempre identici, e ripeterli
	// nasconderebbe l'unica riga che cambia.
	// La numerazione "Soluzione 1 / Soluzione 2" e' la stessa della pagina
	// del cliente: chi arriva dalla mail deve ritrovare le stesse parole.
	//
	// Senza allegato il pulsante dice anche che da li' si scarica il PDF:
	// come nota grigia sotto al pulsante non lo leggeva nessuno.
	//
	// NB: dentro la tabella non si scrivono commenti Blade. Lasciano una
	// riga di soli spazi, e per CommonMark una riga vuota chiude il blocco
	// HTML grezzo: il resto della tabella finisce stampato come codice
	// dentro la mail.
	$vociDi = fn ($quote) => $quote->quoteProducts
		->map(fn ($riga) => $riga->product?->name)
		->filter()
		->unique()
		->values()
		->all();

	// Le voci che ci sono in TUTTE le alternative non distinguono niente:
	// su due configurazioni dello stesso modello sono sette righe uguali
	// (macinacaffe', dosatori, PrecisionTemp, installazione...) e ripeterle
	// nasconderebbe le due che cambiano davvero.
	$comuni = $quotes->count() > 1
		? array_intersect(...array_map($vociDi, $quotes->all()))
		: [];

	// Il titolo: la macchina, che e' sempre la voce principale.
	$macchineDi = fn ($quote) => $quote->quoteProducts
		->filter(fn ($riga) => $riga->product?->type === \App\Models\Product::TYPE_MACHINE)
		->map(fn ($riga) => $riga->product?->name)
		->filter()
		->unique()
		->values();

	$titoloSoluzione = function ($quote) use ($macchineDi) {
		$nomi = $macchineDi($quote);

		return $nomi->isNotEmpty() ? $nomi->implode(' + ') : $quote->number;
	};

	// E sotto, solo cio' che differenzia questa alternativa dalle altre:
	// l'unita' di raffreddamento, il secondo gruppo, quello che e' stato
	// cambiato per fare il confronto.
	$differenzaDi = function ($quote) use ($vociDi, $comuni, $macchineDi) {
		$macchine = $macchineDi($quote)->all();

		return collect($vociDi($quote))
			->reject(fn ($nome) => in_array($nome, $comuni, true) || in_array($nome, $macchine, true))
			->values();
	};
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;border-collapse:collapse;">
	<tr>
		<td colspan="2" style="background:#020F30;color:#ffffff;padding:7px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">
			{{ $quotes->count() > 1 ? 'Le sue soluzioni' : 'La sua proposta' }}
		</td>
	</tr>
	@foreach($quotes as $quote)
	<tr>
		<td style="background:#ffffff;border:1px solid #e5e7eb;border-top:none;border-right:none;padding:12px;vertical-align:top;">
			@if($quotes->count() > 1)
				<div style="font-size:11px;color:#64748b;letter-spacing:.04em;text-transform:uppercase;">Soluzione {{ $loop->iteration }}</div>
			@endif
			<div style="font-size:14px;font-weight:700;color:#0f172a;margin-top:2px;">{{ $titoloSoluzione($quote) }}</div>
			@if($differenzaDi($quote)->isNotEmpty())
				<div style="font-size:12px;color:#334155;margin-top:3px;">con {{ $differenzaDi($quote)->implode(', ') }}</div>
			@endif
			<div style="font-size:11px;color:#94a3b8;margin-top:3px;">{{ $quote->number }}</div>
		</td>
		<td width="40%" style="background:#ffffff;border:1px solid #e5e7eb;border-top:none;border-left:none;padding:12px;text-align:right;vertical-align:top;">
			@if($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee)
				@php
					$months = max(1, (int) ($quote->rental_months ?? 1));
					$monthlyFee = (float) $quote->rental_monthly_fee;
				@endphp
				<div style="font-size:15px;font-weight:700;color:#0f172a;">€ {{ number_format($monthlyFee, 2, ',', '.') }}<span style="font-size:12px;font-weight:400;">/mese</span></div>
				<div style="font-size:11px;color:#64748b;margin-top:3px;">noleggio per {{ $months }} mesi</div>
				<div style="font-size:11px;color:#64748b;margin-top:3px;">valore € {{ number_format((float) $quote->subtotal, 2, ',', '.') }} + IVA</div>
			@else
				<div style="font-size:15px;font-weight:700;color:#0f172a;">€ {{ number_format((float) $quote->subtotal, 2, ',', '.') }}</div>
				<div style="font-size:11px;color:#64748b;margin-top:3px;">+ IVA</div>
			@endif
		</td>
	</tr>
	@endforeach
	<tr>
		<td colspan="2" style="background:#f9fafb;border:1px solid #e5e7eb;border-top:none;padding:16px 12px 18px;text-align:center;">
			@if(! ($conAllegato ?? true) && ! empty($clientUrl))
				<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;border-collapse:collapse;">
					<tr>
						<td style="background:#020F30;border:1px solid #020F30;">
							<a href="{{ $clientUrl }}" target="_blank" style="display:block;padding:13px 26px;font-size:15px;font-weight:700;text-decoration:none;text-align:center;color:#ffffff;">
								{{ $quotes->count() > 1 ? 'Vedi le soluzioni e scarica i PDF' : 'Vedi il preventivo e scarica il PDF' }}
							</a>
						</td>
					</tr>
				</table>
				<div style="font-size:12px;color:#6b7280;margin-top:14px;">
					Nella sua pagina riservata trova ogni voce in dettaglio, la descrizione delle attrezzature e il PDF da stampare.
				</div>
			@else
				<div style="font-size:12px;color:#334155;">
					<strong>In allegato:</strong>
					@if($conAllegato ?? true)@foreach($quotes as $quote)@if(! $loop->first), @endif<span style="white-space:nowrap;">preventivo-{{ $quote->number }}.pdf</span>@endforeach @endif
					@foreach($nomiAllegatiExtra ?? [] as $nome)@if(! $loop->first || ($conAllegato ?? true)), @endif<span style="white-space:nowrap;">{{ $nome }}</span>@endforeach
				</div>
			@endif
		</td>
	</tr>
</table>

@endif
@php
	// La validita' dell'offerta: la data piu' lontana fra le alternative
	// ancora in attesa. Sulle soluzioni gia' decise non si scrive niente,
	// che non vorrebbe dire nulla.
	$scadenzaOfferta = $quotes
		->reject(fn ($q) => $q->isDecided())
		->map(fn ($q) => $q->scadeIl())
		->filter()
		->max();
@endphp

@if($scadenzaOfferta)
{{-- Detta in una riga sola e senza enfasi: serve a dare una data entro cui
     rispondere, non a mettere fretta. Stessa frase della mail del preventivo
     singolo (mail.quote), al plurale perche' qui le soluzioni sono piu' di
     una. --}}
<div style="margin-top:14px;color:#334155;font-size:13px;">
	L'offerta è valida fino al <strong>{{ $scadenzaOfferta->format('d/m/Y') }}</strong>. Se nel frattempo Le servisse più tempo o una proposta diversa, ce lo faccia sapere: la aggiorniamo volentieri.
</div>
@endif

{{-- Il pulsante per aprire la pagina sta dentro il riquadro delle
     soluzioni, non qui: erano due blocchi che dicevano la stessa cosa a due
     centimetri di distanza. --}}
@if(! empty($clientUrl))
<x-mail.client-response-cta :url="$clientUrl" :tenant="$tenant" :multiple="$quotes->count() > 1" />
@endif

{{-- Chiusura prestampata: sta qui e non nel testo modificabile, cosi' viene
     DOPO il riepilogo e gli allegati invece che in mezzo alla mail. I recapiti
     non si ripetono: li stampa gia' il piede qui sotto. --}}
<div style="margin-top:18px;color:#334155;">
	<div>Restiamo a disposizione per qualsiasi chiarimento.</div>
	<div style="margin-top:10px;">Cordiali saluti,</div>
	<div style="font-weight:700;">{{ $tenant?->legal_name ?: ($tenant?->name ?: config('app.name')) }}</div>
</div>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
