<x-mail::message>
@php
	$piuDiUno = $preventivi->count() > 1;
@endphp

<x-mail.hero
	:kicker="($piuDiUno ? 'Offerta ' : 'Preventivo ').$documento->number"
	title="Ci aggiorna?"
/>

<x-mail.box>
{!! \App\Support\HtmlSicuro::filtra($customMessage ?? '') !!}
</x-mail.box>

<x-mail.sollecito-cta :url="$clientUrl" :tenant="$tenant" :multiple="$piuDiUno" />

{{-- Chiusura prestampata, come sull'offerta di gruppo: sta qui e non nel
     testo modificabile cosi' viene DOPO i pulsanti. Dentro il testo si
     saluterebbe prima di aver chiesto. --}}
<div style="margin-top:18px;color:#334155;">
	<div>La ringraziamo per il tempo dedicato e restiamo a disposizione.</div>
	<div style="margin-top:10px;">Cordiali saluti,</div>
	<div style="font-weight:700;">{{ $tenant?->legal_name ?: ($tenant?->name ?: config('app.name')) }}</div>
</div>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
