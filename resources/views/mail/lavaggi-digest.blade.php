<x-mail::message>
<x-mail.hero
	kicker="Lavaggi impianti"
	:title="$tenant->name"
	:subtitle="count($schedules).' impianti scaduti o in scadenza'"
/>

Questi piani di lavaggio sono gia' scaduti o scadono entro {{ $days }} giorni.

@foreach($schedules as $schedule)
<x-mail.due-card
	:overdue="$schedule->next_due_date->isPast()"
	:status="$schedule->next_due_date->isPast() ? 'Scaduto' : 'In scadenza'"
	:date="$schedule->next_due_date->format('d/m/Y')"
	:title="\App\Support\DisplayName::titleCase($schedule->customer?->full_name) ?? '—'"
>
<p style="margin: 0 0 6px; font-size: 14px; color: #3f3f46;">{{ \App\Filament\Resources\MaintenanceScheduleResource::impiantoHero($schedule) }}</p>
<p style="margin: 0; font-size: 13px; color: #71717a;">Ultimo lavaggio: {{ $schedule->lastLavaggio?->data?->format('d/m/Y') ?? '—' }}</p>
</x-mail.due-card>
@endforeach

<x-mail::button :url="\App\Filament\Resources\MaintenanceScheduleResource::getUrl('index', tenant: $tenant)">
Apri i piani di manutenzione
</x-mail::button>

Ricevi questa mail ogni settimana: un piano resta in elenco finche' non viene registrato un nuovo lavaggio, che sposta in avanti la sua prossima scadenza.

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
