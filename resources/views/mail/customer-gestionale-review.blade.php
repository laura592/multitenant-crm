<x-mail::message>
<x-mail.hero
	kicker="Da controllare"
	title="Da controllare su Eureka"
	:subtitle="'Cliente: '.\App\Support\DisplayName::titleCase($customer->full_name)"
/>

<x-mail.severity-panel color="amber">
{{ $reason }}
</x-mail.severity-panel>

<x-mail::button :url="\App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $customer], tenant: $customer->tenant)">
Apri il cliente
</x-mail::button>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$customer->tenant" />
</x-slot:footer>
</x-mail::message>
