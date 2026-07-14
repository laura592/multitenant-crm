<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Riepilogo ore {{ $month }}/{{ $year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <h1>Riepilogo ore {{ $month }}/{{ $year }}</h1>
    <table>
        <thead>
            <tr><th>Dipendente</th><th>Ore ordinarie</th><th>Straordinario</th><th>Giorni ferie</th><th>Ore permesso</th></tr>
        </thead>
        <tbody>
        @foreach($rows as $row)
            <tr>
                <td>{{ $row['user'] }}</td>
                <td>{{ $row['ordinarie'] }}</td>
                <td>{{ $row['straordinario'] }}</td>
                <td>{{ $row['ferie_giorni'] }}</td>
                <td>{{ $row['permessi_ore'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
