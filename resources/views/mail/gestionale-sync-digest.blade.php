<x-mail::message>
<x-mail.hero
	kicker="Sync Eureka"
	:title="$tenant->name"
	subtitle="Riepilogo del controllo automatico tra il CRM e Eureka"
/>

@php
    $summaryRows = array_filter([
        ['label' => 'Da rivedere', 'count' => count($diffs)],
        ['label' => 'Macchine importate automaticamente', 'count' => count($newMachines)],
        ['label' => 'Collegamenti proposti — clienti', 'count' => count($customerLinks)],
        ['label' => 'Collegamenti proposti — prodotti', 'count' => count($productLinks)],
        ['label' => 'Collegamenti proposti — macchinari', 'count' => count($machineUnitLinks)],
        ['label' => 'Compilati automaticamente', 'count' => count($autofilled)],
    ], fn ($row) => $row['count'] > 0);
@endphp

@if(count($apiIssues))
## Eureka ha rifiutato alcune chiamate

<x-mail.severity-panel color="red">
Le funzioni che dipendono dagli endpoint qui sotto **non hanno prodotto risultati**: quello che manca in questo riepilogo potrebbe non essere "niente da segnalare", ma dati che non siamo riusciti a leggere. Un `403` significa che le nostre credenziali non hanno i diritti per quel modulo — va chiesto a chi gestisce Eureka.
</x-mail.severity-panel>

@component('mail::table')
| Endpoint | Chiamate fallite | Risposte |
| :--- | ---: | :--- |
@foreach($apiIssues as $issue)
| {{ $issue['endpoint'] }} | {{ $issue['failures'] }} / {{ $issue['attempts'] }} | {{ $issue['statuses'] }} |
@endforeach
@endcomponent
@endif

@if(count($summaryRows))
@component('mail::table')
| Cosa | Righe |
| :--- | ---: |
@foreach($summaryRows as $row)
| {{ $row['label'] }} | {{ $row['count'] }} |
@endforeach
@endcomponent
@elseif(! count($apiIssues))
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
| {{ \App\Support\DisplayName::titleCase($row['customer']->full_name) }} | {{ implode('; ', $row['fields']) }} |
@endforeach
@endcomponent
@endif

@if(count($newMachines))
## Macchine importate automaticamente ({{ count($newMachines) }})
<x-mail.severity-panel color="green">
Macchinari risultati installati presso un cliente su Eureka: creati direttamente come macchinari nel CRM (matricola e modello reali). La "Proprietà" ha un valore segnaposto da correggere con calma — Eureka non la fornisce.
</x-mail.severity-panel>

@component('mail::table')
| Cliente | Matricola | Modello |
| :--- | :--- | :--- |
@foreach($newMachines as $row)
| {{ \App\Support\DisplayName::titleCase($row['customer']->full_name) }} | {{ $row['machineUnit']->serial_number }} | {{ $row['machineUnit']->model_name ?? '—' }} |
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
| {{ \App\Support\DisplayName::titleCase($row['customer']->full_name) }} | {{ $row['label'] }} (id {{ $row['id'] }}) |
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
| {{ \App\Support\DisplayName::titleCase($row['customer']->full_name) }} | {{ implode(', ', $row['fields']) }} |
@endforeach
@endcomponent
@endif

<x-mail::button :url="\App\Filament\Resources\CustomerResource::getUrl('index', tenant: $tenant)">
Apri i clienti
</x-mail::button>

Usa i filtri "Da aggiornare su Eureka" e "Collegamento proposto" per trovare rapidamente le righe segnalate qui, oppure vai direttamente alla pagina "Sync Eureka" per tutto insieme, incluse le macchine importate di recente.

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
