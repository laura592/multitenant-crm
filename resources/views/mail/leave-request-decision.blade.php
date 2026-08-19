<x-mail::message>
@php
	$type = match ($leaveRequest->type) {
		\App\Models\LeaveRequest::TYPE_FERIE => 'ferie',
		\App\Models\LeaveRequest::TYPE_PERMESSO => 'permesso',
		\App\Models\LeaveRequest::TYPE_MALATTIA => 'malattia',
		default => $leaveRequest->type,
	};
	$period = $leaveRequest->periodLabel();
	$approved = $leaveRequest->status === 'approvato';
@endphp

<x-mail.hero
	:tone="$approved ? 'dark' : 'red'"
	:kicker="'Richiesta '.$type"
	:title="$approved ? 'Approvata' : 'Rifiutata'"
	:subtitle="$leaveRequest->user?->name.' — '.$period"
/>

Ciao {{ $leaveRequest->user?->name }},

la tua richiesta di **{{ $type }}** per il periodo **{{ $period }}** è stata
@if($approved)
**approvata**.
@else
**rifiutata**.
@endif

@if($leaveRequest->notes)
Note: {{ $leaveRequest->notes }}

@endif
<x-slot:footer>
<x-mail.footer-tenant :tenant="$leaveRequest->tenant" />
</x-slot:footer>
</x-mail::message>
