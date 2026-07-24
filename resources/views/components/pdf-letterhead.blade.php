@props(['tenant'])

@php
    $hasLogo = $tenant?->logo_path && file_exists(public_path('storage/'.$tenant->logo_path));
    $hasFrankePartnerLogo = file_exists(public_path('img/franke_partner_logo.png'));
    $addressLine = $tenant?->pdfAddressLine();
    $fiscalLine = $tenant?->pdfFiscalLine();
    $contactLine = $tenant?->pdfContactLine();
@endphp

{{-- Senza logo il blocco aziendale resta comunque leggibile: il nome in
grande evita che l'intestazione sembri vuota/rotta quando il profilo
tenant (Amministrazione > Aziende partner) non ha ancora logo/indirizzo. --}}
<table class="pdf-letterhead">
    <tr>
        @if($hasLogo)
            <td class="logo">
                <div class="logo-stack">
                    <img class="alex-logo" src="{{ public_path('storage/'.$tenant->logo_path) }}" alt="Logo">
                    @if($hasFrankePartnerLogo)
                        <img class="franke-partner-logo" src="{{ public_path('img/franke_partner_logo.png') }}" alt="Franke Approved Partner">
                    @endif
                </div>
            </td>
        @endif
        <td class="{{ $hasLogo ? 'to-right' : '' }}">
            @if($tenant)
                <div class="company-name" style="{{ $hasLogo ? '' : 'font-size: 20px;' }}">{{ $tenant->legal_name ?: $tenant->name }}</div>
                @if($addressLine || $fiscalLine || $contactLine)
                    <div class="company-details">
                        {{ $addressLine }}
                        @if($addressLine && ($contactLine || $fiscalLine))<br>@endif
                        {{ $contactLine }}
                        @if($contactLine && $fiscalLine)<br>@endif
                        {{ $fiscalLine }}
                    </div>
                @endif
            @endif
        </td>
        @if(! $hasLogo && $hasFrankePartnerLogo)
            <td class="logo to-right">
                <div class="logo-stack" style="margin-left:auto;">
                    <img class="franke-partner-logo" src="{{ public_path('img/franke_partner_logo.png') }}" alt="Franke Approved Partner">
                </div>
            </td>
        @endif
    </tr>
</table>
