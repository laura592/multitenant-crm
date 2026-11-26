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

<div style="margin-top:18px;font-size:14px;font-weight:700;color:#0f172a;">Riepilogo soluzioni</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;border-collapse:collapse;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
	<thead>
		<tr>
			<th style="text-align:left;padding:10px 12px;background:#f8fafc;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Preventivo</th>
			<th style="text-align:right;padding:10px 12px;background:#f8fafc;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Totale proposta</th>
		</tr>
	</thead>
	<tbody>
	@foreach($quotes as $quote)
		<tr>
			<td style="padding:10px 12px;border-bottom:1px solid #eef2f7;color:#0f172a;font-size:13px;"><strong>{{ $quote->number }}</strong></td>
			<td style="padding:10px 12px;border-bottom:1px solid #eef2f7;text-align:right;color:#0f172a;font-size:13px;">
			@if($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee)
				@php
					$months = max(1, (int) ($quote->rental_months ?? 1));
					$monthlyFee = (float) $quote->rental_monthly_fee;
				@endphp
				<strong>€ {{ number_format((float) $quote->subtotal, 2, ',', '.') }}</strong> + IVA
				<div style="font-size:11px;color:#64748b;margin-top:2px;">Canone: {{ number_format($monthlyFee, 2, ',', '.') }}/mese x {{ $months }} mesi</div>
			@else
				<strong>€ {{ number_format((float) $quote->subtotal, 2, ',', '.') }}</strong> + IVA
			@endif
			</td>
		</tr>
	@endforeach
	</tbody>
</table>

@if(($conAllegato ?? true) || ($nomiAllegatiExtra ?? []) !== [])
<div style="margin-top:10px;font-size:12px;color:#334155;">
	<strong>Allegati inclusi:</strong>
	@if($conAllegato ?? true)
	@foreach($quotes as $quote)
		@if(!$loop->first), @endif<span style="white-space:nowrap;">preventivo-{{ $quote->number }}.pdf</span>
	@endforeach
	@endif
	@foreach($nomiAllegatiExtra ?? [] as $nome)
		@if(! $loop->first || ($conAllegato ?? true)), @endif<span style="white-space:nowrap;">{{ $nome }}</span>
	@endforeach
</div>
@endif

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

@if(! empty($clientUrl))
@if(! ($conAllegato ?? true))
<x-mail.quote-link :url="$clientUrl" :multiple="$quotes->count() > 1" />
@endif

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
