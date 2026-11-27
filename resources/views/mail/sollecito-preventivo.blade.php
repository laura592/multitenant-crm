<x-mail::message>
@php
	$piuDiUno = $preventivi->count() > 1;
	// Il kicker segue il DOCUMENTO, non il numero di alternative rimaste:
	// un numero OFF- si chiama "Offerta" anche quando di soluzioni ne e'
	// rimasta una sola. Vedi SollecitoPreventivoMail::corpoPredefinito().
	$etichetta = $documento instanceof \App\Models\QuoteGroup ? 'Offerta ' : 'Preventivo ';
	// Si nomina il cliente, non chi paga (stessa regola di QuoteResource).
	// Serve perche' questa mail finisce spesso su un indirizzo di ufficio
	// condiviso, dove "Ci aggiorna?" da solo non dice su che cosa.
	$destinatario = $documento->customer;
	$nome = \App\Support\DisplayName::titleCase($destinatario?->company_name)
		?: \App\Support\DisplayName::titleCase($destinatario?->full_name);
@endphp

<x-mail.hero
	:kicker="$etichetta.$documento->number"
	title="Ci aggiorna?"
	:subtitle="$nome ? 'Destinatario: '.$nome : null"
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
