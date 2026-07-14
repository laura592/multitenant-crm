<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rapportino {{ $report->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .section-title { font-weight: bold; margin-top: 16px; margin-bottom: 4px; }
        .signature-box { margin-top: 24px; }
        .signature-box img { max-width: 300px; border: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <h1>Rapportino di Intervento {{ $report->number }}</h1>
    <p class="muted">{{ $report->tenant->name }}</p>

    <table>
        <tr><th>Cliente</th><td>{{ $report->customer->company_name ?: $report->customer->full_name }}</td></tr>
        <tr><th>Data intervento</th><td>{{ $report->intervention_date->format('d/m/Y') }}</td></tr>
        <tr><th>Tipo intervento</th><td>{{ $report->intervention_type }}</td></tr>
        <tr><th>Tecnico</th><td>{{ $report->technician->name }}</td></tr>
        @if($report->machine_product_id)
            <tr><th>Macchina</th><td>{{ $report->machineProduct->name }} @if($report->machine_serial_number) (matricola {{ $report->machine_serial_number }}) @endif</td></tr>
        @endif
    </table>

    @if($report->problem_description)
        <div class="section-title">Problema riscontrato</div>
        <p>{{ $report->problem_description }}</p>
    @endif

    <div class="section-title">Lavoro svolto</div>
    <p>{{ $report->work_performed }}</p>

    @if($report->partsUsed->isNotEmpty())
        <div class="section-title">Ricambi/materiali utilizzati</div>
        <table>
            <thead><tr><th>Prodotto</th><th>Quantità</th></tr></thead>
            <tbody>
            @foreach($report->partsUsed as $part)
                <tr><td>{{ $part->product->name }}</td><td>{{ $part->quantity }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if($report->customer_signature_path)
        <div class="signature-box">
            <div class="section-title">Firma cliente</div>
            <img src="{{ public_path('storage/'.$report->customer_signature_path) }}" alt="Firma">
        </div>
    @endif
</body>
</html>
