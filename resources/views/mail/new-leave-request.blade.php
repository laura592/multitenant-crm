<x-mail::message>
@php
	$type = match ($leaveRequest->type) {
		\App\Models\LeaveRequest::TYPE_FERIE => 'ferie',
		\App\Models\LeaveRequest::TYPE_PERMESSO => 'permesso',
		\App\Models\LeaveRequest::TYPE_MALATTIA => 'malattia',
		default => $leaveRequest->type,
	};
	$period = $leaveRequest->periodLabel();
@endphp

<x-mail.hero
	:kicker="'Richiesta '.$type"
	:title="$leaveRequest->user?->name"
	:subtitle="$period"
/>

**{{ $leaveRequest->user?->name }}** ha richiesto **{{ $type }}** per il periodo **{{ $period }}**.

@if($leaveRequest->notes)
Note: {{ $leaveRequest->notes }}

@endif
<x-mail::button :url="\App\Filament\Resources\LeaveRequestResource::getUrl('view', ['record' => $leaveRequest], tenant: $leaveRequest->tenant)">
Apri la richiesta
</x-mail::button>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$leaveRequest->tenant" />
</x-slot:footer>
</x-mail::message>
