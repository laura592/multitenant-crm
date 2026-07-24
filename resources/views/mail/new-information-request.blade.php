<x-mail::message>
# Nuova richiesta informazioni {{ $informationRequest->number }}

Cliente: **{{ $informationRequest->customer?->full_name ?: 'Non specificato' }}**

@if($informationRequest->request_details)
{{ $informationRequest->request_details }}

@endif
<x-mail::button :url="\App\Filament\Resources\InformationRequestResource::getUrl('edit', ['record' => $informationRequest], tenant: $informationRequest->tenant)">
Apri la richiesta
</x-mail::button>

Grazie,<br>
{{ $informationRequest->tenant?->name }}
</x-mail::message>
