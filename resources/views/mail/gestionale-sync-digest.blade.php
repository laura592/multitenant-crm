<x-mail::message>
# Sync Eureka — {{ $tenant->name }}

Riepilogo del controllo automatico tra il CRM e Eureka.

@php
    $summaryRows = array_filter([
        ['label' => 'Da rivedere', 'count' => count($diffs)],
        ['label' => 'Macchine nuove trovate su Eureka', 'count' => count($newMachines)],
        ['label' => 'Collegamenti proposti — clienti', 'count' => count($customerLinks)],
        ['label' => 'Collegamenti proposti — prodotti', 'count' => count($productLinks)],
        ['label' => 'Collegamenti proposti — macchinari', 'count' => count($machineUnitLinks)],
        ['label' => 'Compilati automaticamente', 'count' => count($autofilled)],
    ], fn ($row) => $row['count'] > 0);
@endphp

@if(count($summaryRows))
@component('mail::table')
| Cosa | Righe |
| :--- | ---: |
@foreach($summaryRows as $row)
| {{ $row['label'] }} | {{ $row['count'] }} |
@endforeach
@endcomponent
@else
Nessuna segnalazione questa volta: CRM ed Eureka sono allineati.
@endif

@if(count($diffs))
## Da rivedere ({{ count($diffs) }})
<x-mail.severity-panel color="amber">
Campi diversi tra CRM ed Eureka: **non sono stati toccati**, serve una scelta manuale.
</x-mail.severity-panel>

@component('mail::table')
| Cliente | Differenze |
| :--- | :--- |
@foreach($diffs as $row)
| {{ $row['customer']->full_name }} | {{ implode('; ', $row['fields']) }} |
@endforeach
@endcomponent
@endif

@if(count($newMachines))
## Macchine nuove trovate su Eureka ({{ count($newMachines) }})
<x-mail.severity-panel color="amber">
Macchinari risultati installati presso un cliente su Eureka ma non ancora presenti nel CRM — **non creati automaticamente**, da confermare uno per uno.
</x-mail.severity-panel>

@component('mail::table')
| Cliente | Matricola | Modello |
| :--- | :--- | :--- |
@foreach($newMachines as $row)
| {{ $row['customer']->full_name }} | {{ $row['proposal']->serial_number }} | {{ $row['proposal']->model_name ?? '—' }} |
@endforeach
@endcomponent
@endif

@if(count($customerLinks))
## Collegamenti proposti — clienti ({{ count($customerLinks) }})
<x-mail.severity-panel color="blue">
Trovato un possibile cliente corrispondente su Eureka, da confermare.
</x-mail.severity-panel>

@component('mail::table')
| Cliente CRM | Trovato su Eureka |
| :--- | :--- |
@foreach($customerLinks as $row)
| {{ $row['customer']->full_name }} | {{ $row['label'] }} (id {{ $row['id'] }}) |
@endforeach
@endcomponent
@endif

@if(count($productLinks))
## Collegamenti proposti — prodotti ({{ count($productLinks) }})
<x-mail.severity-panel color="blue">
Trovato un possibile prodotto corrispondente su Eureka, da confermare.
</x-mail.severity-panel>

@component('mail::table')
| Prodotto CRM | Trovato su Eureka |
| :--- | :--- |
@foreach($productLinks as $row)
| {{ $row['product']->name }} | {{ $row['label'] }} |
@endforeach
@endcomponent
@endif

@if(count($machineUnitLinks))
## Collegamenti proposti — macchinari ({{ count($machineUnitLinks) }})
<x-mail.severity-panel color="blue">
Matricola trovata su Eureka per un macchinario già collegato a un modello di catalogo.
</x-mail.severity-panel>

@component('mail::table')
| Matricola CRM | Trovata su Eureka |
| :--- | :--- |
@foreach($machineUnitLinks as $row)
| {{ $row['machineUnit']->serial_number }} | {{ $row['label'] }} (id {{ $row['id'] }}) |
@endforeach
@endcomponent
@endif

@if(count($autofilled))
## Compilati automaticamente ({{ count($autofilled) }})
<x-mail.severity-panel color="green">
Campi che erano vuoti nel CRM e sono stati riempiti con quanto trovato su Eureka. Nessuna azione richiesta.
</x-mail.severity-panel>

@component('mail::table')
| Cliente | Campi compilati |
| :--- | :--- |
@foreach($autofilled as $row)
| {{ $row['customer']->full_name }} | {{ implode(', ', $row['fields']) }} |
@endforeach
@endcomponent
@endif

<x-mail::button :url="\App\Filament\Resources\CustomerResource::getUrl('index', tenant: $tenant)">
Apri i clienti
</x-mail::button>

Usa i filtri "Da aggiornare su Eureka" e "Collegamento proposto" per trovare rapidamente le righe segnalate qui, oppure vai direttamente alla pagina "Sync Eureka" per tutte le proposte insieme, incluse le macchine nuove.

Grazie,<br>
{{ $tenant->name }}
</x-mail::message>
