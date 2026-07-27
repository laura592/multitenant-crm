<x-mail::message>
@php
    $type = match ($leaveRequest->type) {
        \App\Models\LeaveRequest::TYPE_FERIE => 'ferie',
        \App\Models\LeaveRequest::TYPE_PERMESSO => 'permesso',
        \App\Models\LeaveRequest::TYPE_MALATTIA => 'malattia',
        default => $leaveRequest->type,
    };
    $period = $leaveRequest->date_from->isSameDay($leaveRequest->date_to)
        ? $leaveRequest->date_from->format('d/m/Y')
        : "{$leaveRequest->date_from->format('d/m/Y')} - {$leaveRequest->date_to->format('d/m/Y')}";
@endphp
# Nuova richiesta {{ $type }}

**{{ $leaveRequest->user?->name }}** ha richiesto **{{ $type }}** per il periodo **{{ $period }}**.

@if($leaveRequest->notes)
Note: {{ $leaveRequest->notes }}

@endif
<x-mail::button :url="\App\Filament\Resources\LeaveRequestResource::getUrl('view', ['record' => $leaveRequest], tenant: $leaveRequest->tenant)">
Apri la richiesta
</x-mail::button>

Grazie,<br>
{{ $leaveRequest->tenant?->name }}
</x-mail::message>
