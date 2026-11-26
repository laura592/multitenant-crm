<x-mail::message>
@php
	$tenant = $quote->tenant;
	$customerName = \App\Support\DisplayName::titleCase($quote->customer?->company_name) ?: \App\Support\DisplayName::titleCase($quote->customer?->full_name);
	$heroTitle = $customerName ? "Preventivo per {$customerName}" : "Preventivo {$quote->number}";
@endphp

<x-mail.hero
	:kicker="'Preventivo '.$quote->number"
	:title="$heroTitle"
/>

<x-mail.box>
{!! \App\Support\HtmlSicuro::filtra($customMessage ?? '') !!}
</x-mail.box>

{{-- Detta in una riga sola e senza enfasi: serve a dare una data entro
     cui rispondere, non a mettere fretta. Sui preventivi gia' decisi non
     si scrive. --}}
@if(! $quote->isDecided() && $quote->scadeIl())
Il preventivo è valido fino al **{{ $quote->scadeIl()->format('d/m/Y') }}**. Se nel frattempo Le servisse più tempo o una proposta diversa, ce lo faccia sapere: lo aggiorniamo volentieri.
@endif

@if(! empty($clientUrl))
{{-- Senza allegato il preventivo E' la pagina, quindi va annunciata prima
     di chiedere una risposta: i tre pulsanti qui sotto portano dritti ai
     moduli, e chiedere "accetta o rifiuta" a chi non ha ancora visto il
     documento non ha senso. --}}
@if(! ($conAllegato ?? true))
<x-mail.quote-link :url="$clientUrl" :numero="$quote->number" />
@endif

<x-mail.client-response-cta :url="$clientUrl" :tenant="$tenant" />
@endif

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
