<x-mail::message>
<x-mail.hero
	kicker="Lavaggi impianti"
	:title="$tenant->name"
	:subtitle="count($schedules).' impianti scaduti o in scadenza'"
/>

Questi piani di lavaggio sono gia' scaduti o scadono entro {{ $days }} giorni.

{{-- Una scheda per impianto invece di una tabella a 5 colonne: sul telefono le colonne si schiacciavano fino a spezzare nomi e date. --}}
@foreach($schedules as $schedule)
@php
    $scaduto = $schedule->next_due_date->isPast();
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 10px; border: 1px solid #e2e8f0; border-left: 4px solid {{ $scaduto ? '#dc2626' : '#d97706' }}; border-radius: 6px;">
<tr>
<td style="padding: 12px 14px;">
<p style="margin: 0 0 6px; font-size: 12px; font-weight: bold; color: {{ $scaduto ? '#991b1b' : '#92400e' }}; text-transform: uppercase; letter-spacing: 0.04em;">{{ $scaduto ? 'Scaduto' : 'In scadenza' }} &middot; {{ $schedule->next_due_date->format('d/m/Y') }}</p>
<p style="margin: 0 0 2px; font-size: 16px; font-weight: bold; color: #18181b;">{{ \App\Support\DisplayName::titleCase($schedule->customer?->full_name) ?? '—' }}</p>
<p style="margin: 0 0 6px; font-size: 14px; color: #3f3f46;">{{ \App\Filament\Resources\MaintenanceScheduleResource::impiantoHero($schedule) }}</p>
<p style="margin: 0; font-size: 13px; color: #71717a;">Ultimo lavaggio: {{ $schedule->lastLavaggio?->data?->format('d/m/Y') ?? '—' }}</p>
</td>
</tr>
</table>
@endforeach

<x-mail::button :url="\App\Filament\Resources\MaintenanceScheduleResource::getUrl('index', tenant: $tenant)">
Apri i piani di manutenzione
</x-mail::button>

Ricevi questa mail ogni settimana: un piano resta in elenco finche' non viene registrato un nuovo lavaggio, che sposta in avanti la sua prossima scadenza.

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
