<x-mail::message>
@php
	$tenant = $group->tenant ?: $group->customer?->tenant;
	$resolvedSubject = trim((string) ($subjectText ?? "Offerta {$group->number}"));
	$headerTitle = $tenant?->legal_name ?: ($tenant?->name ?? config('app.name'));
	$headerAddress = $tenant?->pdfAddressLine();
	$headerContacts = $tenant?->pdfContactLine();
	$headerFiscal = $tenant?->pdfFiscalLine();
	$footerCompany = $tenant?->legal_name ?: ($tenant?->name ?? config('app.name'));
	$footerAddress = $tenant?->pdfAddressLine();
	$footerFiscal = $tenant?->pdfFiscalLine();
	$footerContacts = $tenant?->pdfContactLine();
	$renderedBody = trim((string) ($emailBody ?? ''));
	$customerName = $group->customer?->company_name ?: $group->customer?->full_name;
@endphp

<div style="background:#0f172a;border-radius:10px;padding:16px 18px;color:#ffffff;margin-bottom:16px;">
	<div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.75;margin-bottom:8px;">Offerta {{ $group->number }}</div>
	<div style="font-size:18px;line-height:1.35;font-weight:700;">{{ $resolvedSubject }}</div>
	<div style="font-size:13px;opacity:.8;margin-top:10px;">Destinatario: {{ $customerName }}</div>
</div>

<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
	<div style="font-size:14px;font-weight:700;color:#0f172a;">{{ $headerTitle }}</div>
	@if($headerAddress)
		<div style="font-size:12px;color:#475569;margin-top:5px;">{{ $headerAddress }}</div>
	@endif
	@if($headerContacts)
		<div style="font-size:12px;color:#475569;margin-top:2px;">{{ $headerContacts }}</div>
	@endif
	@if($headerFiscal)
		<div style="font-size:12px;color:#475569;margin-top:2px;">{{ $headerFiscal }}</div>
	@endif
</div>

<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
	{!! nl2br(e($renderedBody)) !!}
</div>

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
					$totalRental = $monthlyFee * $months;
				@endphp
				<strong>€ {{ number_format($monthlyFee, 2, ',', '.') }}/mese</strong>
				<div style="font-size:11px;color:#64748b;margin-top:2px;">{{ $months }} mesi - Tot. € {{ number_format($totalRental, 2, ',', '.') }} + IVA</div>
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

<div style="margin-top:22px;padding-top:12px;border-top:1px solid #e2e8f0;color:#64748b;font-size:11px;line-height:1.5;">
	<div style="font-weight:700;color:#334155;">{{ $footerCompany }}</div>
	@if($footerAddress)
		<div>{{ $footerAddress }}</div>
	@endif
	@if($footerFiscal)
		<div>{{ $footerFiscal }}</div>
	@endif
	@if($footerContacts)
		<div>{{ $footerContacts }}</div>
	@endif
</div>
</x-mail::message>
