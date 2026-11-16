<x-mail::message>
@php
	$casetta = $lettura->machineUnit?->serial_number;
	$comune = \App\Support\DisplayName::titleCase($lettura->customer?->company_name);
	$unita = $lettura->unitaBreve();
	$numero = fn ($v, $u) => $v === null ? null : number_format((float) $v, 2, ',', '.').' '.$u;
@endphp

<x-mail.hero
	:kicker="$casetta ? 'Casetta '.$casetta : 'Casetta dell\'acqua'"
	:title="'Lettura del '.$lettura->data->format('d/m/Y')"
	:subtitle="$comune ?: null"
/>

<x-mail.box>
@if ($customMessage)
{!! \App\Support\HtmlSicuro::filtra($customMessage) !!}
@else
<p style="margin:0 0 12px;">Buongiorno,</p>
<p style="margin:0 0 12px;">in allegato il rendiconto della lettura del {{ $lettura->data->format('d/m/Y') }}@if($casetta) sulla casetta {{ $casetta }}@endif@if($comune) di {{ $comune }}@endif, con le foto dei display.</p>
@php
	$righe = array_filter([
		'Acqua erogata nel periodo' => $numero($lettura->erogato(), $unita),
		'Incasso del periodo' => $numero($lettura->incassoPeriodo(), '€'),
		'Venduto del periodo' => $numero($lettura->vendutoPeriodo(), '€'),
	]);
@endphp
@if ($righe !== [])
{{-- Le differenze con la lettura precedente, non i totali dei contatori:
     e' quello che si fattura. Se manca la lettura precedente non c'e' niente
     da scrivere e il riquadro sparisce. --}}
<table style="width:100%;border-collapse:collapse;margin:0 0 12px;">
@foreach ($righe as $etichetta => $valore)
	<tr>
		<td style="padding:3px 0;color:#4b5563;">{{ $etichetta }}</td>
		<td style="padding:3px 0;text-align:right;font-weight:bold;color:#020F30;">{{ $valore }}</td>
	</tr>
@endforeach
</table>
@endif
<p style="margin:0;">I numeri per esteso e le foto sono nel PDF allegato.</p>
@endif
</x-mail.box>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$lettura->tenant" />
</x-slot:footer>
</x-mail::message>
