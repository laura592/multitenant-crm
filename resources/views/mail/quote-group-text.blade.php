@php
    $resolvedSubject = trim((string) ($subjectText ?? "Offerta {$group->number}"));
    $renderedBody = trim((string) ($emailBody ?? ''));
@endphp

Offerta {{ $group->number }}
{{ $resolvedSubject }}

{!! $renderedBody !== '' ? e($renderedBody) : '' !!}

@if($quotes->isNotEmpty())
RIEPILOGO SOLUZIONI
@foreach($quotes as $quote)
@if($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee)
@php
    $months = max(1, (int) ($quote->rental_months ?? 1));
    $monthlyFee = (float) $quote->rental_monthly_fee;
@endphp
- {{ $quote->number }}: € {{ number_format((float) $quote->subtotal, 2, ',', '.') }} + IVA (canone {{ number_format($monthlyFee, 2, ',', '.') }}/mese x {{ $months }} mesi)
@else
- {{ $quote->number }}: € {{ number_format((float) $quote->subtotal, 2, ',', '.') }} + IVA
@endif
@endforeach

ALLEGATI
@foreach($quotes as $quote)
- preventivo-{{ $quote->number }}.pdf
@endforeach
@endif
