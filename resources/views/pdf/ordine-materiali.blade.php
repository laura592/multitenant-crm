<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Ordine materiali {{ $date->format('d/m/Y') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        .letterhead { width: 100%; border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 16px; }
        .letterhead td { border: none; padding: 0; vertical-align: top; }
        .letterhead .logo img { max-height: 60px; max-width: 180px; }
        .letterhead .company-name { font-size: 16px; font-weight: bold; }
        .letterhead .company-details { color: #4b5563; font-size: 10px; line-height: 1.5; }
        .letterhead .to-right { text-align: right; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .meta { color: #4b5563; margin-bottom: 14px; }
        .meta span { margin-right: 18px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th { text-align: left; padding: 5px 6px; background: #f3f4f6; border-bottom: 2px solid #d1d5db; }
        table.items td { text-align: left; padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
        table.items td.qty { text-align: center; font-weight: bold; }
        .notes { margin-top: 18px; }
        .notes h2 { font-size: 12px; margin-bottom: 4px; }
    </style>
</head>
<body>
    <table class="letterhead">
        <tr>
            <td class="logo">
                @if($tenant?->logo_path && file_exists(public_path('storage/'.$tenant->logo_path)))
                    <img src="{{ public_path('storage/'.$tenant->logo_path) }}" alt="Logo">
                @endif
            </td>
            <td class="to-right">
                @if($tenant)
                    <div class="company-name">{{ $tenant->legal_name ?: $tenant->name }}</div>
                    <div class="company-details">
                        @if($tenant->street || $tenant->postal_code || $tenant->city)
                            {{ trim("{$tenant->street}, {$tenant->postal_code} {$tenant->city} {$tenant->province}", ' ,') }}<br>
                        @endif
                        @if($tenant->vat_number)
                            P.IVA {{ $tenant->vat_number }}
                        @endif
                        @if($tenant->tax_code)
                            &nbsp;&ndash;&nbsp;C.F. {{ $tenant->tax_code }}
                        @endif
                        <br>
                        @if($tenant->phone)
                            Tel. {{ $tenant->phone }}
                        @endif
                        @if($tenant->email)
                            &nbsp;&ndash;&nbsp;{{ $tenant->email }}
                        @endif
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <h1>Ordine materiali {{ $number }}</h1>
    <div class="meta">
        <span><strong>Data:</strong> {{ $date->format('d/m/Y H:i') }}</span>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Codice</th>
                <th>Categoria</th>
                <th>Tipo</th>
                <th>Tubo Ø</th>
                <th>Filetto</th>
                <th>Codolo Ø</th>
                <th class="qty">Quantità</th>
            </tr>
        </thead>
        <tbody>
        @foreach($rows as $row)
            @php $material = $row['material']; @endphp
            <tr>
                <td>{{ $material->code }}</td>
                <td>{{ $material->category }}</td>
                <td>
                    {{ $material->type }}
                    @if($material->variant)
                        <br><small>{{ $material->variant }}</small>
                    @endif
                </td>
                <td>
                    {{ $material->tube_diameter }}
                    @if($material->tube_diameter_2)
                        &ndash; {{ $material->tube_diameter_2 }}
                    @endif
                </td>
                <td>
                    {{ $material->thread_size }}
                    @if($material->thread_type)
                        {{ $material->thread_type }}
                    @endif
                </td>
                <td>{{ $material->barb_diameter }}</td>
                <td class="qty">{{ $row['quantity'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if($notes)
        <div class="notes">
            <h2>Note</h2>
            <p>{{ $notes }}</p>
        </div>
    @endif
</body>
</html>
