<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Ordine materiali {{ $date->format('d/m/Y') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        table.items td.qty, table.items th.qty { text-align: center; }
        table.items td.qty { font-weight: bold; font-size: 12px; }
        table.items small { color: #6b7280; }

        .category-row td { background: #eef1f5 !important; font-weight: bold; font-size: 10.5px; padding: 7px 6px; border-bottom: 1px solid #d1d5db; border-top: 1px solid #d1d5db; }
        .category-row .cat-count { float: right; font-weight: normal; color: #6b7280; }

        .totals-row td { border-top: 2px solid #020F30; border-bottom: none; font-weight: bold; padding-top: 8px; }
        .totals-row .qty { font-size: 13px; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Ordine materiali</span><br><span class="value">{{ $number }}</span></td>
            <td class="to-right"><span class="label">Data</span><br><span class="value">{{ $date->format('d/m/Y') }}</span></td>
        </tr>
    </table>

    @if($supplier)
        <div class="section-title">Spett.le</div>
        <div class="info-box" style="margin-bottom: 14px;">
            <div class="customer-name">{{ $supplier->name }}</div>
            <table>
                @if($supplier->address || $supplier->postal_code || $supplier->city)
                    <tr><td class="label">Sede:</td><td>{{ trim("{$supplier->address}, {$supplier->postal_code} {$supplier->city} {$supplier->province}", ' ,') }}</td></tr>
                @endif
                @if($supplier->phone)
                    <tr><td class="label">Tel:</td><td>{{ $supplier->phone }}</td></tr>
                @endif
                @if($supplier->email)
                    <tr><td class="label">Email:</td><td>{{ $supplier->email }}</td></tr>
                @endif
            </table>
        </div>
    @endif

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
        <div class="notes-box">
            <h2>Note</h2>
            <p>{{ $notes }}</p>
        </div>
    @endif

    <div class="footer-note">Generato automaticamente il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
