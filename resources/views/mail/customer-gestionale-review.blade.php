<x-mail::message>
# Da controllare su Eureka

Cliente: **{{ $customer->full_name }}**

<x-mail.severity-panel color="amber">
{{ $reason }}
</x-mail.severity-panel>

<x-mail::button :url="\App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $customer], tenant: $customer->tenant)">
Apri il cliente
</x-mail::button>

Grazie,<br>
{{ $customer->tenant?->name }}
</x-mail::message>
