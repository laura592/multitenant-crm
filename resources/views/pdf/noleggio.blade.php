<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Noleggio operativo — {{ $noleggio->customer?->company_name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.5; }
        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        h2.sezione { margin: 16px 0 4px; font-size: 11px; color: #020F30; text-transform: uppercase; letter-spacing: .04em; }
        /* Il canone e' il numero che si cerca: deve staccarsi dal resto. */
        table.canone { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.canone td { padding: 4px 6px; }
        table.canone td.v { text-align: right; white-space: nowrap; }
        table.canone tr.totale td { border-top: 1.5px solid #020F30; font-weight: bold; font-size: 13px; background: #f0f4fa; }
        .condizioni li { margin-bottom: 4px; }
        /* Firme: devono stare insieme, non spezzarsi a fine pagina. */
        .firme { page-break-inside: avoid; margin-top: 26px; }
        .firme td { padding-top: 26px; font-size: 9px; color: #6b7280; }
        .riga-firma { border-top: 1px solid #9ca3af; padding-top: 3px; width: 45%; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Contratto di noleggio operativo</span><br>
                <span class="value">{{ $noleggio->data_inizio?->format('d/m/Y') ?: now()->format('d/m/Y') }}</span></td>
            <td><span class="label">Durata</span><br><span class="value">{{ $noleggio->mesi }} mesi</span></td>
        </tr>
    </table>

    <h2 class="sezione">Cliente</h2>
    <div class="info-box">
        <strong>{{ $noleggio->customer?->company_name }}</strong><br>
        {{-- La provincia manca su parecchie anagrafiche: senza il controllo
             restavano le parentesi vuote, "Abano Terme ()". --}}
        {{ $noleggio->customer?->street }}@if($noleggio->customer?->postal_code), {{ $noleggio->customer?->postal_code }} {{ $noleggio->customer?->city }}@if(filled($noleggio->customer?->province)) ({{ $noleggio->customer->province }})@endif
        @endif
        @if($noleggio->customer?->vat_number)<br>P. IVA {{ $noleggio->customer->vat_number }}@endif
    </div>

    <h2 class="sezione">Oggetto del noleggio</h2>
    <div class="info-box">
        {{ $noleggio->descrizione }}
        @if($noleggio->machineUnit)<br><span style="color:#6b7280;">Matricola {{ $noleggio->machineUnit->serial_number }}</span>@endif
    </div>

    <h2 class="sezione">Canone</h2>
    {{--
        Le tre voci si mostrano al cliente perche' il canone sia leggibile:
        "cosa sto pagando" e' la prima domanda. Non si espongono invece costo
        d'acquisto, margine e mese di pareggio: sono conti interni.
    --}}
    <table class="canone">
        <tr><td>Disponibilità dell'attrezzatura</td><td class="v">&euro; {{ number_format((float) $noleggio->quota_macchina, 2, ',', '.') }}</td></tr>
        <tr><td>Assistenza full-service (manutenzioni programmate, ricambi, manodopera e trasferte)</td><td class="v">&euro; {{ number_format((float) $noleggio->quota_servizio, 2, ',', '.') }}</td></tr>
        @if ((float) $noleggio->quota_detergenti > 0)
            <tr><td>Detergenti e materiali di consumo</td><td class="v">&euro; {{ number_format((float) $noleggio->quota_detergenti, 2, ',', '.') }}</td></tr>
        @endif
        @if ((float) $noleggio->quota_caffe > 0)
            <tr><td>Fornitura di caffè</td><td class="v">&euro; {{ number_format((float) $noleggio->quota_caffe, 2, ',', '.') }}</td></tr>
        @endif
        <tr class="totale"><td>Canone mensile, IVA esclusa</td><td class="v">&euro; {{ number_format((float) $noleggio->canone, 2, ',', '.') }}</td></tr>
    </table>
    <p style="margin-top:6px;color:#6b7280;">
        Impegno complessivo su {{ $noleggio->mesi }} mesi: &euro; {{ number_format((float) $noleggio->canone * $noleggio->mesi, 2, ',', '.') }} + IVA.
    </p>

    @php
        // Composte qui e non inline: Blade non compila una direttiva attaccata
        // a una parola ("consumo@if"), e i @if finivano stampati nel PDF.
        $kg = (float) $noleggio->caffe_kg_mese;
        $kgTesto = rtrim(rtrim(number_format($kg, 2, ',', '.'), '0'), ',');

        $frasiDetergenti = filled($noleggio->detergenti_inclusi)
            ? 'Il canone comprende i detergenti e i materiali di consumo nella misura di <strong>'.e($noleggio->detergenti_inclusi).'</strong>; i quantitativi eccedenti sono fatturati a consumo.'
            : 'Il canone comprende i detergenti e i materiali di consumo necessari all\'uso ordinario.';

        $fraseCaffe = (float) $noleggio->quota_caffe <= 0
            ? '<strong>Il caff&egrave; non &egrave; compreso</strong> ed &egrave; fatturato a consumo.'
            : ($kg > 0
                ? '<strong>La fornitura di caff&egrave; &egrave; compresa</strong> nel canone fino a <strong>'.$kgTesto.' kg al mese</strong>; i quantitativi eccedenti sono fatturati a consumo.'
                : '<strong>La fornitura di caff&egrave; &egrave; compresa</strong> nel canone, nei quantitativi concordati.');
    @endphp

    <h2 class="sezione">Condizioni</h2>
    <ul class="condizioni">
        <li><strong>L'attrezzatura resta di proprietà di {{ $tenant?->legal_name ?: $tenant?->name }}</strong> per tutta la durata del contratto e al termine va restituita, salvo diverso accordo scritto.</li>
        <li>Il canone comprende le manutenzioni programmate, i ricambi, la manodopera e le trasferte previste dal programma full-service.</li>
        @if ((float) $noleggio->quota_detergenti > 0)
            <li>{!! $frasiDetergenti !!}</li>
        @endif
        {{-- La riga cambia senso a seconda che il caffe' sia nel canone o no:
             scriverla fissa significherebbe, in un caso o nell'altro, dire al
             cliente il contrario di quello che pagherà. --}}
        <li>{!! $fraseCaffe !!}</li>
        <li>L'installazione e l'allacciamento sono a nostro carico; le predisposizioni — punto acqua, scarico e alimentazione elettrica — restano a carico del Cliente e vanno realizzate prima dell'intervento.</li>
        <li>Sono esclusi i danni da uso improprio, le manomissioni e gli interventi effettuati da personale non autorizzato.</li>
        <li><strong>Durata minima {{ $noleggio->mesi }} mesi.</strong> In caso di recesso anticipato restano dovuti i canoni residui, salvo diverso accordo scritto.</li>
    </ul>

    @if (filled($noleggio->note))
        <h2 class="sezione">Note</h2>
        <div class="info-box">{!! nl2br(e($noleggio->note)) !!}</div>
    @endif

    <table class="firme" style="width:100%;">
        <tr>
            <td><div class="riga-firma">{{ $tenant?->legal_name ?: $tenant?->name }}</div></td>
            <td style="width:10%;"></td>
            <td><div class="riga-firma">Il Cliente, per accettazione</div></td>
        </tr>
    </table>

    <div class="footer-note">{{ $tenant?->legal_name ?: $tenant?->name }} &mdash; Documento generato il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
