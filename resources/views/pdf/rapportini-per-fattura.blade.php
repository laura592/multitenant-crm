{{--
    I rapportini di una fattura in un PDF solo: niente riepilogo davanti,
    proprio i rapportini uno dopo l'altro, come se fossero stampati insieme
    ("non voglio un riepilogo, voglio proprio i rapportini nell'allegato",
    ufficio 12/10/2026).

    Variabili: $rapportini (Collection), $showPrices, $tenant.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $fattura ? 'Rapportini '.$fattura : 'Rapportini' }}</title>
    <style>
        @include('pdf.partials.service-report-styles')

        {{-- page-break-before sul rapportino successivo e non -after sul
             precedente: con -after dompdf chiude il documento con una pagina
             bianca. Il primo non ha interruzione davanti. --}}
        .rapportino-dopo { page-break-before: always; }
    </style>
</head>
<body>
    @foreach($rapportini as $i => $r)
        <div @class(['rapportino-dopo' => $i > 0])>
            @include('pdf.partials.service-report-body', [
                'report' => $r,
                'showPrices' => $showPrices,
                'showArticoli' => true,
            ])
        </div>
    @endforeach

    @include('pdf.partials.page-numbers')
</body>
</html>
