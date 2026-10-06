@props(['tenant'])

{{--
    Intestazione dei contratti, diversa da quella degli altri documenti.

    I contratti Full-Service ed Easy-Service, scritti in Word prima del CRM,
    hanno un'intestazione propria: dati in carattere con grazie, piu' piccoli,
    senza IBAN e senza riga di separazione colorata. Un contratto che esce dal
    gestionale deve somigliare a quelli, non ai preventivi — li firma lo stesso
    cliente e li legge uno accanto all'altro (Laura, 06/10/2026).

    Niente IBAN di proposito: in un contratto non serve, e su un documento che
    gira sta meglio fuori. Nelle fatture e nei preventivi resta.
--}}
@php
    $hasLogo = $tenant?->logo_path && file_exists(public_path('storage/'.$tenant->logo_path));
    $hasFranke = file_exists(public_path('img/franke_partner_logo.png'));
@endphp

<table class="letterhead-contratto">
    <tr>
        <td class="logo">
            @if($hasLogo)
                <img class="alex-logo" src="{{ public_path('storage/'.$tenant->logo_path) }}" alt="Logo">
            @endif
            @if($hasFranke)
                <img class="franke-logo" src="{{ public_path('img/franke_partner_logo.png') }}" alt="Franke Approved Partner">
            @endif
        </td>
        <td class="dati">
            @if($tenant)
                <div class="nome">{{ $tenant->legal_name ?: $tenant->name }}</div>
                @if($tenant->pdfAddressLine())<div>{{ $tenant->pdfAddressLine() }}</div>@endif
                @if($tenant->phone)<div>Tel. {{ $tenant->phone }}</div>@endif
                {{-- L'indirizzo aziendale sta in `email` ed e' una PEC: si
                     etichetta come tale solo se lo e' davvero. --}}
                @if($tenant->email)<div>@if(str_contains($tenant->email, 'pec'))PEC @endif{{ $tenant->email }}</div>@endif
                @if($tenant->pdfFiscalLine())<div>{{ $tenant->pdfFiscalLine() }}</div>@endif
            @endif
        </td>
    </tr>
</table>
