{{--
    I rapportini di una fattura, in un PDF solo: prima l'elenco di cosa c'e'
    dentro, poi ogni rapportino per intero.

    Nasce dalla domanda che arriva dal cliente ("questa fattura cos'e'?"):
    l'elenco serve a rispondere in un colpo d'occhio, i rapportini completi a
    mandare le prove senza aprirne uno alla volta.

    Variabili: $fattura (etichetta o null), $cliente, $rapportini (Collection),
    $showPrices, $tenant.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $fattura ? 'Rapportini '.$fattura : 'Rapportini senza fattura' }}</title>
    <style>
        @include('pdf.partials.service-report-styles')

        {{-- page-break-before e non -after sull'elemento precedente: con
             -after dompdf lascia una pagina bianca in fondo al documento. --}}
        .rapportino-dopo { page-break-before: always; }
        .riepilogo-fattura td { padding: 4px 6px; }
        .riepilogo-fattura .numeric { text-align: right; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <div class="section-title">{{ $fattura ? 'Rapportini della fattura '.$fattura : 'Rapportini senza fattura collegata' }}</div>

    <table class="row-table" style="margin-bottom: 12px;">
        <tr>
            <td class="header-cell col-half">
                <table class="info-box">
                    <tr><td class="label">Cliente:</td><td>{{ $cliente ?: '—' }}</td></tr>
                    <tr><td class="label">Rapportini:</td><td>{{ $rapportini->count() }}</td></tr>
                </table>
            </td>
            <td class="col-gap"></td>
            <td class="header-cell col-half">
                <table class="info-box">
                    <tr><td class="label">Periodo:</td><td>
                        {{ optional($rapportini->min('intervention_date'))->format('d/m/Y') }}
                        — {{ optional($rapportini->max('intervention_date'))->format('d/m/Y') }}
                    </td></tr>
                    <tr><td class="label">Stampato il:</td><td>{{ now()->format('d/m/Y') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items riepilogo-fattura" style="margin-bottom: 14px;">
        <thead>
            <tr>
                <th>Rapportino</th><th>Data</th><th>Cliente</th><th>Macchina</th>
                @if($showPrices)<th class="numeric">Importo</th>@endif
            </tr>
        </thead>
        <tbody>
        @foreach($rapportini as $r)
            <tr>
                <td>{{ $r->number }}</td>
                <td>{{ $r->intervention_date?->format('d/m/Y') }}</td>
                <td>{{ \App\Support\DisplayName::titleCase($r->customer?->company_name) }}</td>
                <td>{{ $r->machine_model_name ?: '—' }}{{ $r->machine_serial_number ? ' — '.$r->machine_serial_number : '' }}</td>
                @if($showPrices)
                    <td class="numeric">€ {{ number_format((float) $r->materialsUsed->sum('line_total_snapshot'), 2, ',', '.') }}</td>
                @endif
            </tr>
        @endforeach
        @if($showPrices)
            <tr>
                <td colspan="4" class="numeric"><strong>Totale articoli</strong></td>
                <td class="numeric"><strong>€ {{ number_format((float) $rapportini->sum(fn ($r) => (float) $r->materialsUsed->sum('line_total_snapshot')), 2, ',', '.') }}</strong></td>
            </tr>
        @endif
        </tbody>
    </table>

    @php($totaleArticoli = $rapportini->sum(fn ($r) => (float) $r->materialsUsed->sum('line_total_snapshot')))
    @if($showPrices && $totaleArticoli <= 0)
        <div class="footer-note">
            Gli importi per articolo non sono valorizzati su questi rapportini: il totale della fattura va letto su Eureka.
        </div>
    @endif

    @foreach($rapportini as $r)
        <div class="rapportino-dopo">
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
