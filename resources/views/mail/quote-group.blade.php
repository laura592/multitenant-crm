<x-mail::message>
# Offerta {{ $group->number }}

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
@endphp

<x-mail::panel>
**Oggetto**  
{{ $resolvedSubject }}
</x-mail::panel>

<x-mail::panel>
**{{ $headerTitle }}**  
@if($headerAddress)
{{ $headerAddress }}  
@endif
@if($headerContacts)
{{ $headerContacts }}  
@endif
@if($headerFiscal)
{{ $headerFiscal }}
@endif
</x-mail::panel>

<x-mail::panel>
{!! nl2br(e($renderedBody)) !!}
</x-mail::panel>

@if($quotes->isNotEmpty())
## Riepilogo soluzioni

<x-mail::table>
| Preventivo | Totale proposta |
| :-- | --: |
@foreach($quotes as $quote)
@if($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee)
@php
	$months = max(1, (int) ($quote->rental_months ?? 1));
	$monthlyFee = (float) $quote->rental_monthly_fee;
	$totalRental = $monthlyFee * $months;
@endphp
| **{{ $quote->number }}** | € {{ number_format($monthlyFee, 2, ',', '.') }}/mese x {{ $months }} mesi (tot. € {{ number_format($totalRental, 2, ',', '.') }} + IVA) |
@else
| **{{ $quote->number }}** | € {{ number_format((float) $quote->subtotal, 2, ',', '.') }} + IVA |
@endif
@endforeach
</x-mail::table>

**Allegati inclusi:**
@foreach($quotes as $quote)
- preventivo-{{ $quote->number }}.pdf
@endforeach

@endif

---

<small>
**{{ $footerCompany }}**  
@if($footerAddress)
{{ $footerAddress }}  
@endif
@if($footerFiscal)
{{ $footerFiscal }}  
@endif
@if($footerContacts)
{{ $footerContacts }}
@endif
</small>
</x-mail::message>