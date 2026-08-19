<x-mail::message>
@php
	$tenant = $group->tenant ?: $group->customer?->tenant;
	$resolvedSubject = trim((string) ($subjectText ?? "Offerta {$group->number}"));
	$renderedBody = trim((string) ($emailBody ?? ''));
	$recipient = $group->customer?->invoiceRecipient();
	$customerName = $recipient?->company_name ?: $recipient?->full_name;
@endphp

<x-mail.hero
	:kicker="'Offerta '.$group->number"
	:title="$resolvedSubject"
	:subtitle="'Destinatario: '.$customerName"
/>

<x-mail.box>
{!! nl2br(e($renderedBody)) !!}
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

<div style="margin-top:10px;font-size:12px;color:#334155;">
	<strong>Allegati inclusi:</strong>
	@foreach($quotes as $quote)
		@if(!$loop->first), @endif<span style="white-space:nowrap;">preventivo-{{ $quote->number }}.pdf</span>
	@endforeach
</div>

@endif

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
