@props(['url', 'tenant' => null, 'multiple' => false])
@php
	// Il commerciale (Aziende > Contatto per i clienti), non centralino e PEC.
	$contactName = $tenant?->client_contact_name;
	$phone = $tenant?->client_contact_phone ?: $tenant?->phone;
	$link = fn (string $azione) => $url.(str_contains($url, '?') ? '&' : '?').'azione='.$azione;
	$cell = 'display:block;padding:12px 10px;font-size:14px;font-weight:700;text-decoration:none;text-align:center;';
@endphp
{{-- Il link personale alla pagina del preventivo (QuoteClientController),
con lo stile del PDF (navy #020F30, angoli vivi): ogni pulsante apre la
pagina gia' sul modulo giusto. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;border-collapse:collapse;">
	<tr>
		<td style="background:#020F30;color:#ffffff;padding:7px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">
			La sua risposta
		</td>
	</tr>
	<tr>
		<td style="background:#f9fafb;border:1px solid #e5e7eb;border-top:none;padding:14px 12px 16px;">
			<div style="font-size:13px;color:#374151;margin-bottom:12px;">
				{{ $multiple ? 'Può scegliere e firmare la soluzione che preferisce online, chiederci informazioni o dirci che non le interessa.' : 'Può accettare e firmare il preventivo online, chiederci informazioni o dirci che non le interessa.' }}
			</div>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;">
				<tr>
					<td width="38%" style="background:#047857;border:1px solid #047857;">
						<a href="{{ $link('accetta') }}" target="_blank" style="{{ $cell }}color:#ffffff;">✓ {{ $multiple ? 'Scegli e firma' : 'Accetta e firma' }}</a>
					</td>
					<td width="2%" style="font-size:0;">&nbsp;</td>
					<td width="31%" style="background:#ffffff;border:1px solid #020F30;">
						<a href="{{ $link('domanda') }}" target="_blank" style="{{ $cell }}color:#020F30;">Richiedi info</a>
					</td>
					<td width="2%" style="font-size:0;">&nbsp;</td>
					<td width="27%" style="background:#ffffff;border:1px solid #9ca3af;">
						<a href="{{ $link('rifiuta') }}" target="_blank" style="{{ $cell }}color:#4b5563;">Rifiuta</a>
					</td>
				</tr>
			</table>
			@if($phone)
				<div style="font-size:12px;color:#6b7280;margin-top:12px;">
					Per informazioni: {{ $contactName ? $contactName.' – ' : '' }}<a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" style="color:#020F30;font-weight:700;text-decoration:none;white-space:nowrap;">{{ $phone }}</a>
				</div>
			@endif
		</td>
	</tr>
</table>
