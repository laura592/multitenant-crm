@props(['url', 'multiple' => false, 'numero' => null])
{{-- Il preventivo come pagina, non come allegato.
     Quando la mail parte senza PDF questo e' il blocco principale: il
     cliente deve capire in un colpo d'occhio dove sta il documento. Il PDF
     non sparisce — si scarica da li' (QuoteClientController::pdf), e in
     cambio sappiamo se l'ha aperto, cosa che con l'allegato non si e' mai
     potuta sapere. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;border-collapse:collapse;">
	<tr>
		<td style="background:#020F30;color:#ffffff;padding:7px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">
			{{ $multiple ? 'Le sue soluzioni' : 'Il suo preventivo' }}
		</td>
	</tr>
	<tr>
		<td style="background:#ffffff;border:1px solid #e5e7eb;border-top:none;padding:16px 12px 18px;text-align:center;">
			<div style="font-size:13px;color:#374151;margin-bottom:14px;">
				{{ $multiple
					? 'Trova tutte le soluzioni, voce per voce, nella sua pagina riservata.'
					: 'Trova il preventivo completo, voce per voce, nella sua pagina riservata.' }}
			</div>
			<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;border-collapse:collapse;">
				<tr>
					<td style="background:#020F30;border:1px solid #020F30;">
						<a href="{{ $url }}" target="_blank" style="display:block;padding:13px 26px;font-size:15px;font-weight:700;text-decoration:none;text-align:center;color:#ffffff;">
							{{ $multiple ? 'Vedi le soluzioni' : 'Vedi il preventivo' }}{{ $numero ? ' '.$numero : '' }}
						</a>
					</td>
				</tr>
			</table>
			<div style="font-size:12px;color:#6b7280;margin-top:14px;">
				Dalla pagina può anche scaricare e stampare il PDF.
			</div>
		</td>
	</tr>
</table>
