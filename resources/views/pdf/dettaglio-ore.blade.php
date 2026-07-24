<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Dettaglio ore {{ $month }}/{{ $year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        tr.user-header td { font-weight: bold; background: #f3f4f6; border-top: 2px solid #d1d5db; }
    </style>
</head>
<body>
    <h1>Dettaglio ore {{ $month }}/{{ $year }}</h1>
    <table>
        <thead>
            <tr><th>Dipendente</th><th>Data</th><th>Ore lavorate</th><th>Ordinarie</th><th>Straordinario</th><th>Assenza</th></tr>
        </thead>
        <tbody>
        @foreach($rows as $row)
            <tr>
                <td>{{ $row['user'] }}</td>
                <td>{{ $row['date']->format('d/m/Y') }}</td>
                <td>{{ $row['ore_lavorate'] }}</td>
                <td>{{ $row['ordinarie'] }}</td>
                <td>{{ $row['straordinario'] }}</td>
                <td>{{ $row['assenza'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @include('pdf.partials.page-numbers')
</body>
</html>
