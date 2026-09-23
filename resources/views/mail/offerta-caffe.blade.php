<x-mail::message>
@php
	$tenant = $offerta->tenant ?? $cliente->tenant;
	$customerName = \App\Support\DisplayName::titleCase($cliente->company_name) ?: \App\Support\DisplayName::titleCase($cliente->full_name);
@endphp

<x-mail.hero
	:kicker="'Offerta caffè '.$offerta->number"
	:title="$customerName ? 'Offerta caffè per '.$customerName : 'Offerta caffè'"
/>

<x-mail.box>
{!! \App\Support\HtmlSicuro::filtra($customMessage ?? '') !!}
</x-mail.box>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
