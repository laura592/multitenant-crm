<x-mail::message>
# Ordine materiali {{ $order->number }}

Gentile {{ $order->supplier->name ?? 'Fornitore' }},

in allegato l'ordine materiali {{ $order->number }} del {{ $order->created_at->format('d/m/Y') }}.

@if($order->notes)
**Note:** {{ $order->notes }}
@endif

Cordiali saluti,<br>
{{ $order->tenant->name }}
</x-mail::message>
