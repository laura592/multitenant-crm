@php
    // Il sottotitolo dice quale delle due liste c'e' dentro: la mail parte
    // anche quando le scadenze urgenti sono zero ma un mezzo e' scoperto.
    $pezzi = [];
    if (count($deadlines)) {
        $pezzi[] = count($deadlines).' scadenze da controllare';
    }
    if (count($mezziIncompleti)) {
        $pezzi[] = count($mezziIncompleti) === 1
            ? '1 mezzo senza documenti in corso'
            : count($mezziIncompleti).' mezzi senza documenti in corso';
    }
@endphp
<x-mail::message>
<x-mail.hero
	kicker="Scadenzario"
	:title="$tenant->name"
	:subtitle="implode(' · ', $pezzi)"
/>

@if(count($deadlines))
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
@endif

@if(count($mezziIncompleti))
## Mezzi senza documenti in corso

Per questi mezzi non risulta **nessuna scadenza attiva** di uno dei documenti obbligatori. Non e' una scadenza vicina: e' una scadenza che non c'e', e finche' manca nessun promemoria potra' avvisare.

@foreach($mezziIncompleti as $mezzo)
- **{{ $mezzo->plate }}** — {{ $mezzo->descrizione() }} — manca: {{ implode(', ', $mezzo->etichetteScadenzeMancanti()) }}
@endforeach

Se il documento esiste ma non e' a sistema, basta inserirlo con la sua data. Se invece la pratica vecchia e' stata segnata "rinnovata" senza aprire quella nuova, il mezzo resta scoperto in questo elenco.

<x-mail::button :url="\App\Filament\Resources\VehicleResource::getUrl('index', tenant: $tenant)">
Apri i mezzi
</x-mail::button>
@endif

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
