<x-mail::message>
@php
	$tenant = $report->tenant;
	$customerName = $report->customer->company_name ?: $report->customer->full_name;
@endphp

<x-mail.hero
	kicker="Rapportino {{ $report->number }}"
	title="Intervento del {{ $report->intervention_date->format('d/m/Y') }}"
	subtitle="Cliente: {{ $customerName }}"
/>

<x-mail.box>
@if ($customMessage)
{!! $customMessage !!}
@else
<p style="margin:0 0 12px;">Gentile {{ $customerName }},</p>
<p style="margin:0 0 12px;">in allegato il rapportino relativo all'intervento del {{ $report->intervention_date->format('d/m/Y') }}.</p>
<p style="margin:0;"><strong>Lavoro svolto:</strong> {{ $report->work_performed }}</p>
@endif
</x-mail.box>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
