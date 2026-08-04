<x-mail::message>
# Preventivo {{ $quote->number }}

{!! nl2br(e($customMessage ?? '')) !!}
</x-mail::message>
