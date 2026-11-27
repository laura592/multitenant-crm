@props(['url', 'tenant' => null, 'multiple' => false])
@php
	$contactName = $tenant?->client_contact_name;
	$phone = $tenant?->client_contact_phone ?: $tenant?->phone;
	$link = fn (string $azione) => $url.(str_contains($url, '?') ? '&' : '?').'azione='.$azione;
	// 13px e non 14: "Per ora non procedo" a 14 andava a capo
	// spezzando la parola a meta' ("proced o").
	$cell = 'display:block;padding:12px 6px;font-size:13px;font-weight:700;text-decoration:none;text-align:center;';
@endphp
{{-- I pulsanti del SOLLECITO, diversi da quelli dell'invio (vedi
     client-response-cta). Diciture scelte da Laura, 27/11/2026.

     Qui non si chiede di firmare: si chiede un riscontro. "Accetta e firma"
     davanti a chi non ha ancora deciso chiede troppo in un colpo solo, e
     "Rifiuta" suona definitivo quando spesso e' solo "non adesso".

     "Vorrei parlarne" porta alla richiesta di richiamata, non al modulo
     delle domande scritte: chi vuole parlarne vuole il telefono. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;border-collapse:collapse;">
	<tr>
		<td style="background:#020F30;color:#ffffff;padding:7px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">
			Il suo riscontro
		</td>
	</tr>
	<tr>
		<td style="background:#f9fafb;border:1px solid #e5e7eb;border-top:none;padding:14px 12px 16px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;">
				<tr>
					<td width="38%" style="background:#047857;border:1px solid #047857;">
						<a href="{{ $link('accetta') }}" target="_blank" style="{{ $cell }}color:#ffffff;">{{ $multiple ? 'Una proposta mi interessa' : 'La proposta mi interessa' }}</a>
					</td>
					<td width="2%" style="font-size:0;">&nbsp;</td>
					<td width="24%" style="background:#ffffff;border:1px solid #020F30;">
						<a href="{{ $link('richiamata') }}" target="_blank" style="{{ $cell }}color:#020F30;">Vorrei parlarne</a>
					</td>
					<td width="2%" style="font-size:0;">&nbsp;</td>
					<td width="34%" style="background:#ffffff;border:1px solid #9ca3af;">
						<a href="{{ $link('rifiuta') }}" target="_blank" style="{{ $cell }}color:#4b5563;">Per ora non procedo</a>
					</td>
				</tr>
			</table>
			@if($phone)
				<div style="font-size:12px;color:#6b7280;margin-top:12px;">
					Oppure ci chiami: {{ $contactName ? $contactName.' – ' : '' }}<a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" style="color:#020F30;font-weight:700;text-decoration:none;white-space:nowrap;">{{ $phone }}</a>
				</div>
			@endif
		</td>
	</tr>
</table>
