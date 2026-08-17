@props(['tenant'])
@php
	$footerCompany = $tenant?->legal_name ?: ($tenant?->name ?? config('app.name'));
@endphp
<div style="margin-top:22px;padding-top:12px;border-top:1px solid #e2e8f0;color:#64748b;font-size:11px;line-height:1.5;">
	<div style="font-weight:700;color:#334155;">{{ $footerCompany }}</div>
	@if($tenant?->pdfAddressLine())
		<div>{{ $tenant->pdfAddressLine() }}</div>
	@endif
	@if($tenant?->pdfFiscalLine())
		<div>{{ $tenant->pdfFiscalLine() }}</div>
	@endif
	@if($tenant?->ibanLine())
		<div>{{ $tenant->ibanLine() }}</div>
	@endif
	@if($tenant?->pdfContactLine())
		<div>{{ $tenant->pdfContactLine() }}</div>
	@endif
</div>
