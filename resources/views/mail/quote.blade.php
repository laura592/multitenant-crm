<x-mail::message>
@php
	$tenant = $quote->tenant;
	$customerName = $quote->customer?->company_name ?: $quote->customer?->full_name;
	$heroTitle = $customerName ? "Preventivo per {$customerName}" : "Preventivo {$quote->number}";
@endphp

<x-mail.hero
	:kicker="'Preventivo '.$quote->number"
	:title="$heroTitle"
/>

<x-mail.box>
{!! $customMessage ?? '' !!}
</x-mail.box>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
