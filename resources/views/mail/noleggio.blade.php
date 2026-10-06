<x-mail::message>
@php
	$tenant = $noleggio->tenant ?: $cliente?->tenant;
	$resolvedSubject = trim((string) ($subjectText ?? 'Contratto di noleggio operativo'));
	$renderedBody = trim((string) ($customMessage ?? ''));
	// Come nelle offerte: il rich editor manda HTML, ma un testo salvato a
	// righe va ancora convertito, altrimenti si stampa il markup com'e'.
	$bodyHtml = $renderedBody === ''
		? ''
		: ($renderedBody !== strip_tags($renderedBody) ? $renderedBody : nl2br(e($renderedBody)));
	$customerName = \App\Support\DisplayName::titleCase($cliente?->company_name)
		?: \App\Support\DisplayName::titleCase($cliente?->full_name);
	$eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');

	// Le stesse diciture del contratto: la mail non deve dire una cosa
	// diversa da quella che il cliente firma.
	$condizioni = implode(', ', array_filter([
		\App\Models\Noleggio::periodicitaLabels()[$noleggio->periodicita_fatturazione] ?? null,
		\App\Models\Noleggio::modalitaPagamentoLabels()[$noleggio->modalita_pagamento] ?? null,
		\App\Models\Noleggio::terminiPagamentoLabels()[$noleggio->termini_pagamento] ?? null,
	]));

	$righe = array_filter([
		'Canone' => $eur($noleggio->canone).' al mese + IVA',
		'Durata' => ((int) $noleggio->mesi).' mesi',
		'Decorrenza' => $noleggio->data_inizio?->format('d/m/Y'),
		'Pagamento' => $condizioni ?: null,
	]);
@endphp

<x-mail.hero
	kicker="Noleggio operativo"
	:title="$resolvedSubject"
	:subtitle="'Destinatario: '.$customerName"
/>

<x-mail.box>
{!! \App\Support\HtmlSicuro::filtra($bodyHtml) !!}
</x-mail.box>

<div style="margin-top:18px;font-size:14px;font-weight:700;color:#0f172a;">In sintesi</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;border-collapse:collapse;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
	<tbody>
	@foreach($righe as $etichetta => $valore)
		<tr>
			<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:13px;">{{ $etichetta }}</td>
			<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;color:#0f172a;font-size:13px;">{{ $valore }}</td>
		</tr>
	@endforeach
	</tbody>
</table>

<div style="margin-top:12px;color:#475569;font-size:13px;">
	<strong>Allegato:</strong> il contratto completo, con il dettaglio di cosa è compreso nel canone.
</div>

{{-- Chiusura prestampata, come nelle offerte: sta qui e non nel testo
     modificabile, cosi' viene dopo il riepilogo invece che in mezzo alla
     mail. I recapiti non si ripetono, li stampa il piede. --}}
<div style="margin-top:18px;color:#334155;">
	<div>Restiamo a disposizione per qualsiasi chiarimento.</div>
	<div style="margin-top:10px;">Cordiali saluti,</div>
	<div style="font-weight:700;">{{ $tenant?->legal_name ?: ($tenant?->name ?: config('app.name')) }}</div>
</div>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
