<x-mail::message>
# Preventivo {{ $quote->number }}

@php($recipient = $quote->customer?->invoiceRecipient())
Gentile {{ $recipient?->company_name ?: $recipient?->full_name }},

@if($customMessage)
{{ $customMessage }}

@else
in allegato il preventivo richiesto.

@endif
**€ {{ number_format((float) $quote->subtotal, 2, ',', '.') }} + IVA**

Restiamo a disposizione per qualsiasi chiarimento.

Grazie,<br>
{{ $quote->tenant?->name }}
</x-mail::message>
