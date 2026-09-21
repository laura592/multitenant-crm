<x-mail::message>
<x-mail.hero
	kicker="Scadenzario"
	:title="$tenant->name"
	:subtitle="count($deadlines).' scadenze da controllare'"
/>

Queste sono le scadenze in avvicinamento o gia' scadute, da controllare.

@foreach($deadlines as $deadline)
<x-mail.due-card
	:overdue="$deadline->due_date->isPast()"
	:status="$deadline->due_date->isPast() ? 'Scaduta' : 'In avvicinamento'"
	:date="$deadline->due_date->format('d/m/Y')"
	:title="\App\Models\Deadline::typeLabels()[$deadline->type] ?? 'Altro'"
>
<p style="margin: 0; font-size: 14px; color: #3f3f46;">{{ $deadline->relatedLabel() }}</p>
</x-mail.due-card>
@endforeach

<x-mail::button :url="\App\Filament\Resources\DeadlineResource::getUrl('index', tenant: $tenant)">
Apri lo scadenzario
</x-mail::button>

Ricevi questa mail ogni settimana finche' la scadenza non viene rinnovata o segnata come pagata.

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
