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
# Richiesta {{ $type }} {{ $approved ? 'approvata' : 'rifiutata' }}

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
Grazie,<br>
{{ $leaveRequest->tenant?->name }}
</x-mail::message>
