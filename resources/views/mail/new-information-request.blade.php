<x-mail::message>
<x-mail.hero
	kicker="Richiesta informazioni {{ $informationRequest->number }}"
	title="Nuova richiesta"
	subtitle="Cliente: {{ $informationRequest->customer?->full_name ?: 'Non specificato' }}"
/>

@if($informationRequest->request_details)
{{ $informationRequest->request_details }}

@endif
<x-mail::button :url="\App\Filament\Resources\InformationRequestResource::getUrl('edit', ['record' => $informationRequest], tenant: $informationRequest->tenant)">
Apri la richiesta
</x-mail::button>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$informationRequest->tenant" />
</x-slot:footer>
</x-mail::message>
