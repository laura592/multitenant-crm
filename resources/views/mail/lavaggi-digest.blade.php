<x-mail::message>
<x-mail.hero
	kicker="Lavaggi impianti"
	:title="$tenant->name"
	:subtitle="count($schedules).' impianti scaduti o in scadenza'"
/>

Questi piani di lavaggio sono gia' scaduti o scadono entro {{ $days }} giorni.

@component('mail::table')
| Cliente | Impianto | Ultimo lavaggio | Scadenza | Stato |
| :--- | :--- | :--- | :--- | :--- |
@foreach($schedules as $schedule)
| {{ \App\Support\DisplayName::titleCase($schedule->customer?->full_name) ?? '—' }} | {{ \App\Filament\Resources\MaintenanceScheduleResource::impiantoHero($schedule) }} | {{ $schedule->lastLavaggio?->data?->format('d/m/Y') ?? '—' }} | {{ $schedule->next_due_date->format('d/m/Y') }} | {{ $schedule->next_due_date->isPast() ? 'Scaduto' : 'In scadenza' }} |
@endforeach
@endcomponent

<x-mail::button :url="\App\Filament\Resources\MaintenanceScheduleResource::getUrl('index', tenant: $tenant)">
Apri i piani di manutenzione
</x-mail::button>

Ricevi questa mail ogni settimana: un piano resta in elenco finche' non viene registrato un nuovo lavaggio, che sposta in avanti la sua prossima scadenza.

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
