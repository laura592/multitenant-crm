<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Ordine materiali {{ $date->format('d/m/Y') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }

        @include('pdf.partials.letterhead-styles')

        .title-bar { width: 100%; margin-bottom: 16px; }
        .title-bar td { border: none; padding: 0; vertical-align: bottom; }
        h1 { font-size: 19px; margin: 0; }
        .order-number { color: #6b7280; font-size: 12px; margin-top: 2px; }
        .meta-box { text-align: right; font-size: 10px; color: #4b5563; }
        .meta-box .meta-label { color: #9ca3af; text-transform: uppercase; font-size: 8px; letter-spacing: .04em; }
        .meta-box .meta-value { font-size: 12px; color: #111827; font-weight: bold; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th { text-align: left; padding: 6px 6px; background: #111827; color: #fff; font-size: 9.5px; text-transform: uppercase; letter-spacing: .03em; }
        table.items td { text-align: left; padding: 6px 6px; border-bottom: 1px solid #e5e7eb; }
        table.items tr:nth-child(even) td { background: #f9fafb; }
        table.items td.qty, table.items th.qty { text-align: center; }
        table.items td.qty { font-weight: bold; font-size: 12px; }
        table.items small { color: #6b7280; }

        .category-row td { background: #eef1f5 !important; font-weight: bold; font-size: 10.5px; padding: 7px 6px; border-bottom: 1px solid #d1d5db; border-top: 1px solid #d1d5db; }
        .category-row .cat-count { float: right; font-weight: normal; color: #6b7280; }

        .totals-row td { border-top: 2px solid #111827; border-bottom: none; font-weight: bold; padding-top: 8px; }
        .totals-row .qty { font-size: 13px; }

        .notes { margin-top: 20px; padding: 10px 12px; background: #f9fafb; border-left: 3px solid #111827; }
        .notes h2 { font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; margin: 0 0 4px; }
        .notes p { margin: 0; }

        .footer-note { margin-top: 24px; font-size: 9px; color: #9ca3af; text-align: center; }

        .supplier-box { width: 280px; margin-bottom: 16px; padding: 8px 12px; border: 1px solid #d1d5db; }
        .supplier-box .supplier-label { font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #9ca3af; }
        .supplier-box .supplier-name { font-size: 13px; font-weight: bold; }
        .supplier-box .supplier-details { font-size: 10px; color: #4b5563; line-height: 1.5; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    @if($supplier)
        <div class="supplier-box">
            <div class="supplier-label">Spett.le</div>
            <div class="supplier-name">{{ $supplier->name }}</div>
            @if($supplier->address || $supplier->postal_code || $supplier->city)
                <div class="supplier-details">{{ trim("{$supplier->address}, {$supplier->postal_code} {$supplier->city} {$supplier->province}", ' ,') }}</div>
            @endif
            @if($supplier->phone || $supplier->email)
                <div class="supplier-details">
                    @if($supplier->phone)
                        Tel. {{ $supplier->phone }}
                    @endif
                    @if($supplier->email)
                        &nbsp;&ndash;&nbsp;{{ $supplier->email }}
                    @endif
                </div>
            @endif
        </div>
    @endif

    <table class="title-bar">
        <tr>
            <td>
                <h1>Ordine materiali</h1>
                <div class="order-number">{{ $number }}</div>
            </td>
            <td class="meta-box">
                <div class="meta-label">Data</div>
                <div class="meta-value">{{ $date->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    @php
        $groups = $rows->groupBy(fn ($row) => $row['material']->category);
        $totalQty = $rows->sum('quantity');
    @endphp

    <table class="items">
        <thead>
            <tr>
                <th>Codice</th>
                <th>Descrizione</th>
                <th>Tubo Ø</th>
                <th>Filetto</th>
                <th>Codolo Ø</th>
                <th class="qty">Quantità</th>
            </tr>
        </thead>
        <tbody>
        @foreach($groups as $category => $categoryRows)
            <tr class="category-row">
                <td colspan="6">
                    {{ $category }}
                    <span class="cat-count">{{ $categoryRows->count() }} {{ $categoryRows->count() === 1 ? 'articolo' : 'articoli' }} · {{ $categoryRows->sum('quantity') }} pz</span>
                </td>
            </tr>
            @foreach($categoryRows as $row)
                @php $material = $row['material']; @endphp
                <tr>
                    <td>{{ $material->code }}</td>
                    <td>
                        {{ $material->variant ?: $material->type }}
                        @if($material->variant)
                            <br><small>{{ $material->type }}</small>
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
        @endforeach
        </tbody>
        <tfoot>
            <tr class="totals-row">
                <td colspan="5">Totale &mdash; {{ $rows->count() }} {{ $rows->count() === 1 ? 'articolo' : 'articoli' }}</td>
                <td class="qty">{{ $totalQty }}</td>
            </tr>
        </tfoot>
    </table>

    @if($notes)
        <div class="notes">
            <h2>Note</h2>
            <p>{{ $notes }}</p>
        </div>
    @endif

    <div class="footer-note">Generato automaticamente il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
