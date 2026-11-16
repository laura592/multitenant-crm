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

@php
	// Il testo e' sempre quello che arriva dal modale di invio: li' l'editor
	// parte gia' scritto con testoStandard(). Il fallback serve agli invii
	// che non passano dal pannello.
	$prosa = filled($customMessage)
		? \App\Support\HtmlSicuro::filtra($customMessage)
		: \App\Mail\LetturaCasettaMail::testoStandard($lettura);

	// Le differenze con la lettura precedente, non i totali dei contatori:
	// e' quello che si fattura. Senza lettura precedente non c'e' niente da
	// scrivere e il riquadro sparisce.
	$righe = array_filter([
		'Acqua erogata nel periodo' => $numero($lettura->erogato(), $unita),
		'Incasso del periodo' => $numero($lettura->incassoPeriodo(), '€'),
		'Venduto del periodo' => $numero($lettura->vendutoPeriodo(), '€'),
	]);
@endphp

<x-mail.box>
{!! $prosa !!}
@if ($righe !== [])
<table style="width:100%;border-collapse:collapse;margin:12px 0 0;">
@foreach ($righe as $etichetta => $valore)
	<tr>
		<td style="padding:3px 0;color:#4b5563;">{{ $etichetta }}</td>
		<td style="padding:3px 0;text-align:right;font-weight:bold;color:#020F30;">{{ $valore }}</td>
	</tr>
@endforeach
</table>
@endif
</x-mail.box>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$lettura->tenant" />
</x-slot:footer>
</x-mail::message>
