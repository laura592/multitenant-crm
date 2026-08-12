<x-mail::message>
# Preventivo {{ $quote->number }}

<div>
{!! $customMessage ?? '' !!}
</div>

@php($tenant = $quote->tenant)
<x-slot:footer>
{{ $tenant?->legal_name ?: $tenant?->name }}<br>
@if($tenant?->pdfAddressLine()){{ $tenant->pdfAddressLine() }}<br>@endif
@if($tenant?->pdfContactLine()){{ $tenant->pdfContactLine() }}<br>@endif
@if($tenant?->pdfFiscalLine()){{ $tenant->pdfFiscalLine() }}<br>@endif
@if($tenant?->ibanLine()){{ $tenant->ibanLine() }}<br>@endif
</x-slot:footer>
</x-mail::message>
