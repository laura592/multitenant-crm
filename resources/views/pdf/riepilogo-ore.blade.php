<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Riepilogo ore {{ $month }}/{{ $year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Riepilogo ore</span><br><span class="value">{{ sprintf('%02d', $month) }}/{{ $year }}</span></td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Dipendente</th><th class="numeric">Ore ordinarie</th><th class="numeric">Straordinario</th><th class="numeric">Giorni ferie</th><th class="numeric">Giorni malattia</th><th class="numeric">Ore permesso</th></tr>
        </thead>
        <tbody>
        @foreach($rows as $row)
            <tr>
                <td>{{ $row['user'] }}</td>
                <td class="numeric">{{ $row['ordinarie'] }}</td>
                <td class="numeric">{{ $row['straordinario'] }}</td>
                <td class="numeric">{{ $row['ferie_giorni'] }}</td>
                <td class="numeric">{{ $row['malattia_giorni'] }}</td>
                <td class="numeric">{{ $row['permessi_ore'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer-note">{{ $tenant?->legal_name ?: $tenant?->name }} &mdash; Generato automaticamente il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
