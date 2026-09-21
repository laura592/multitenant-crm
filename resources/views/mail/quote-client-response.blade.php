<x-mail::message>
@php
	use App\Models\QuoteResponse;
	$tenant = $document->tenant;
	$customerName = \App\Support\DisplayName::titleCase($document->customer?->company_name) ?: \App\Support\DisplayName::titleCase($document->customer?->full_name);
	$quote = $response->quote;
	$isGroup = $document instanceof \App\Models\QuoteGroup;
	$title = match ($response->type) {
		QuoteResponse::TYPE_ACCEPTED => 'Il cliente ha accettato e firmato',
		QuoteResponse::TYPE_REJECTED => 'Il cliente ha rifiutato',
		QuoteResponse::TYPE_QUESTION => 'Il cliente ha una domanda',
		default => 'Il cliente chiede di essere richiamato',
	};
	$crmQuote = $quote ?? ($isGroup ? $document->quotes()->withoutGlobalScope('tenant')->orderBy('number')->first() : $document);
	$rows = array_filter([
		'Cliente' => $customerName,
		'Documento' => ($isGroup ? 'Offerta ' : 'Preventivo ').$document->number,
		'Soluzione scelta' => $isGroup && $quote ? $quote->number.' – € '.number_format((float) $quote->subtotal, 2, ',', '.').' + IVA' : null,
		'Totale' => ! $isGroup && $quote && $response->type === QuoteResponse::TYPE_ACCEPTED ? '€ '.number_format((float) $quote->subtotal, 2, ',', '.').' + IVA' : null,
		'Firmato da' => $response->signer_name ? trim($response->signer_name.($response->signer_role ? ' ('.$response->signer_role.')' : '')) : null,
		'Motivo' => $response->reason ? (QuoteResponse::reasonLabels()[$response->reason] ?? $response->reason) : null,
		'Telefono' => $response->phone,
		'Quando' => $response->preferred_time ? (QuoteResponse::preferredTimeLabels()[$response->preferred_time] ?? null) : null,
		'Email' => $response->email,
		'Ricevuto il' => $response->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
	]);
@endphp

<x-mail.hero
	:kicker="($isGroup ? 'Offerta ' : 'Preventivo ').$document->number"
	:title="$title"
	:subtitle="$customerName"
	:tone="$response->type === QuoteResponse::TYPE_REJECTED ? 'red' : 'dark'"
/>

<x-mail.box>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
@foreach($rows as $label => $value)
	<tr>
		<td style="padding:6px 0;color:#64748b;width:38%;vertical-align:top;">{{ $label }}</td>
		<td style="padding:6px 0;color:#0f172a;font-weight:600;">{{ $value }}</td>
	</tr>
@endforeach
</table>
@if($response->message)
<div style="margin-top:12px;padding:12px;background:#f8fafc;border-left:3px solid #316EB4;border-radius:6px;font-size:14px;color:#0f172a;white-space:pre-line;">{{ $response->message }}</div>
@endif
</x-mail.box>

@if($response->type === QuoteResponse::TYPE_ACCEPTED)
<p style="font-size:13px;color:#334155;">In allegato il preventivo firmato dal cliente.</p>
@endif

@if($crmQuote && $tenant)
<x-mail::button :url="\App\Filament\Resources\QuoteResource::getUrl('view', ['record' => $crmQuote], tenant: $tenant)">
Apri il preventivo
</x-mail::button>
@endif

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
