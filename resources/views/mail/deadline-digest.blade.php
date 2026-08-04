<x-mail::message>
# Scadenzario {{ $tenant->name }}

Queste sono le scadenze in avvicinamento o gia' scadute, da controllare.

@component('mail::table')
| Tipo | Collegata a | Scadenza | Stato |
| :--- | :--- | :--- | :--- |
@foreach($deadlines as $deadline)
| {{ \App\Models\Deadline::typeLabels()[$deadline->type] ?? 'Altro' }} | {{ $deadline->relatedLabel() }} | {{ $deadline->due_date->format('d/m/Y') }} | {{ $deadline->due_date->isPast() ? 'Scaduta' : 'In avvicinamento' }} |
@endforeach
@endcomponent

<x-mail::button :url="\App\Filament\Resources\DeadlineResource::getUrl('index', tenant: $tenant)">
Apri lo scadenzario
</x-mail::button>

Ricevi questa mail ogni settimana finche' la scadenza non viene rinnovata o segnata come pagata.

Grazie,<br>
{{ $tenant->name }}
</x-mail::message>
