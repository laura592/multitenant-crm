<x-mail::message>
@php
	$totale = collect($fatture)->sum(fn ($f) => $f->residuo());
	$quante = count($fatture);
	// L'IBAN a gruppi di quattro, come sta stampato sulle fatture: letto al
	// telefono o ricopiato a mano, una stringa di 27 caratteri attaccati si
	// sbaglia.
	$iban = $tenant?->iban ? trim(chunk_split(str_replace(' ', '', $tenant->iban), 4, ' ')) : null;
	$euro = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');

	// ATTENZIONE, se si mette mano al markup qui sotto: questa vista passa
	// per il renderer markdown delle mail, e una RIGA VUOTA dentro un blocco
	// HTML lo chiude — da li' in poi il resto del template arriva al cliente
	// come testo, tag compresi (visto il 27/11/2026). Un commento blade su
	// una riga sua lascia proprio una riga vuota: per questo i commenti
	// stanno tutti qui dentro e non fra i <tr>, e le tabelle dentro un @if
	// cominciano a colonna zero — una riga indentata dopo una riga vuota
	// diventa un blocco di codice.
@endphp

<x-mail.hero
	kicker="Promemoria di pagamento"
	:title="$quante === 1 ? 'Una fattura risulta da saldare' : $quante.' fatture risultano da saldare'"
	:subtitle="'Totale '.$euro($totale)"
/>

<x-mail.box>
{!! \App\Support\HtmlSicuro::filtra($customMessage ?? '') !!}
</x-mail.box>

{{-- Una tabella e non le schede di x-mail.due-card: le righe possono essere
     nove (Ristoalma, 27/11/2026) e nove schede impilate sono due schermate
     di telefono prima di arrivare al totale. Tre colonne sole, con la data
     sotto al numero e il ritardo sotto alla scadenza, cosi' a 390px non si
     schiaccia niente. L'importo e' il RESIDUO, e quando e' diverso dal
     totale della fattura lo dice: il PDF allegato porta l'importo pieno, e
     chi ha gia' pagato un acconto non si riconoscerebbe. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;border-collapse:collapse;">
	<tr>
		<td colspan="3" style="background:#0f172a;color:#ffffff;padding:7px 10px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">{{ $quante === 1 ? 'Il documento' : 'I documenti' }}</td>
	</tr>
	<tr>
		<td style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:6px 10px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Fattura</td>
		<td style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-left:none;padding:6px 10px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Scadenza</td>
		<td align="right" style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-left:none;padding:6px 10px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Importo</td>
	</tr>
	@foreach($fatture as $fattura)
	@php($giorni = $fattura->giorniDiRitardo())
	<tr>
		<td style="border:1px solid #e2e8f0;border-top:none;padding:9px 10px;font-size:13px;color:#0f172a;">
			<span style="font-weight:700;">n. {{ $fattura->numero }}</span>
			@if($fattura->data)<div style="font-size:11px;color:#64748b;margin-top:2px;">del {{ $fattura->data->format('d/m/Y') }}</div>@endif
		</td>
		<td style="border:1px solid #e2e8f0;border-top:none;border-left:none;padding:9px 10px;font-size:13px;color:#0f172a;">
			{{ $fattura->scadenza()?->format('d/m/Y') ?? '—' }}
			@if($giorni)<div style="font-size:11px;color:#b45309;margin-top:2px;">{{ $giorni }} giorni fa</div>@endif
		</td>
		<td align="right" style="border:1px solid #e2e8f0;border-top:none;border-left:none;padding:9px 10px;font-size:13px;color:#0f172a;white-space:nowrap;">
			<span style="font-weight:700;">{{ $euro($fattura->residuo()) }}</span>
			@if($fattura->parziale())<div style="font-size:11px;color:#64748b;margin-top:2px;">di {{ $euro($fattura->totale) }} — saldo residuo</div>@endif
		</td>
	</tr>
	@endforeach
	<tr>
		<td colspan="2" style="border:1px solid #e2e8f0;border-top:none;padding:9px 10px;font-size:13px;font-weight:700;color:#0f172a;">Totale</td>
		<td align="right" style="border:1px solid #e2e8f0;border-top:none;border-left:none;padding:9px 10px;font-size:14px;font-weight:700;color:#0f172a;white-space:nowrap;">{{ $euro($totale) }}</td>
	</tr>
</table>

{{-- Le coordinate in chiaro nella mail e non solo sul PDF: chi paga dal
     telefono apre la mail, non l'allegato, e un IBAN ricopiato da un
     documento scansionato e' il modo piu' comune di sbagliare un bonifico. --}}
@if($iban)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;border-collapse:collapse;">
	<tr>
		<td style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #0f172a;padding:12px 14px;">
			<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Dove pagare</div>
			<div style="font-size:14px;font-weight:700;color:#0f172a;margin-top:4px;letter-spacing:.02em;">{{ $iban }}</div>
			<div style="font-size:12px;color:#334155;margin-top:4px;">Intestato a {{ $tenant?->legal_name ?: ($tenant?->name ?: config('app.name')) }}</div>
		</td>
	</tr>
</table>
@endif

@if($allegati !== [])
<div style="margin-top:16px;font-size:12px;color:#64748b;">In allegato {{ count($allegati) === 1 ? 'la copia della fattura' : 'le copie delle '.count($allegati).' fatture' }}: {{ implode(', ', $allegati) }}.</div>
@endif

{{-- Chiusura prestampata, fuori dal testo modificabile: dentro verrebbe
     prima della tabella, e si saluterebbe prima di aver detto di che cosa si
     parla. --}}
<div style="margin-top:18px;color:#334155;">
	<div>La ringraziamo per la collaborazione e restiamo a disposizione.</div>
	<div style="margin-top:10px;">Cordiali saluti,</div>
	<div style="font-weight:700;">{{ $tenant?->legal_name ?: ($tenant?->name ?: config('app.name')) }}</div>
</div>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
