{{-- Le macchine che un pagante si accolla, da mandargli.

     Raggruppate per cliente, perche' e' cosi' che le legge chi le paga:
     "presso il Chiosco Soleado ne ho tre". --}}
@php
    $perCliente = $macchine->groupBy(fn ($m) => $m->currentCustomer?->company_name ?: '— cliente non indicato');
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #111; }
        h1 { font-size: 13pt; margin: 0 0 2px; }
        .sub { color: #555; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: .3px;
             color: #555; border-bottom: 1px solid #999; padding: 3px 4px; }
        td { padding: 3px 4px; border-bottom: .5px solid #ddd; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .cliente td { background: #f4f6fa; font-weight: bold; border-bottom: 1px solid #ccc; }
        .muted { color: #888; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>Macchine pagate da {{ \App\Support\DisplayName::titleCase($pagante->company_name) ?: \App\Support\DisplayName::titleCase($pagante->full_name) }}</h1>
    <div class="sub">
        {{ $macchine->count() }} {{ $macchine->count() === 1 ? 'macchina' : 'macchine' }}
        presso {{ $perCliente->count() }} {{ $perCliente->count() === 1 ? 'cliente' : 'clienti' }}
        · stampato il {{ now()->format('d/m/Y') }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:34%">Cliente</th>
                <th style="width:20%">Matricola</th>
                <th>Macchina</th>
                <th style="width:16%">Stato</th>
            </tr>
        </thead>
        <tbody>
        @forelse($perCliente as $cliente => $righe)
            <tr class="cliente">
                <td>{{ \App\Support\DisplayName::titleCase($cliente) }}</td>
                <td colspan="3" class="num">{{ $righe->count() }} {{ $righe->count() === 1 ? 'macchina' : 'macchine' }}</td>
            </tr>
            @foreach($righe as $m)
                <tr>
                    <td></td>
                    <td>{{ $m->serial_number ?: '—' }}</td>
                    <td>{{ $m->model_name ?: '—' }}</td>
                    <td class="muted">{{ $m->status ?: '—' }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="4" class="muted">Nessuna macchina.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
