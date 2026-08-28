<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Appuntamenti {{ $from->format('d/m/Y') }} - {{ $to->format('d/m/Y') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        {{-- Lista da stampare e portarsi dietro: la giornata e' la chiave di
             lettura, quindi ogni giorno ha la sua barra e le sue righe invece
             di una tabella unica in cui la data si ripete su ogni riga. --}}
        .day-title { background: #f0f4fa; border-left: 3px solid #020F30; padding: 4px 8px; font-size: 10px; font-weight: bold; color: #020F30; margin: 12px 0 4px; }
        table.items td { font-size: 9px; }
        .col-ora { width: 8%; }
        .col-cliente { width: 27%; }
        .col-zona { width: 15%; }
        .col-contatti { width: 20%; }
        .col-note { width: 30%; }
        .muted { color: #6b7280; font-size: 8.5px; }
        .empty { padding: 14px; background: #f9fafb; border: 1px solid #e5e7eb; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <x-pdf-letterhead :tenant="$tenant" />

    <table class="doc-meta">
        <tr>
            <td><span class="label">Appuntamenti dal</span><span class="value">{{ $from->format('d/m/Y') }}</span>
                <span class="label" style="margin-left:10px">al</span><span class="value">{{ $to->format('d/m/Y') }}</span></td>
            <td class="to-right"><span class="label">Totale</span><span class="value">{{ $requests->count() }}</span></td>
        </tr>
    </table>

    @if($requests->isEmpty())
        <p class="empty">Nessun appuntamento fissato nel periodo selezionato.</p>
    @else
        @foreach($requests->groupBy(fn ($request) => $request->appointment_at->format('Y-m-d')) as $day => $ofTheDay)
            <div class="day-title">{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('l d F Y') }}</div>
            <table class="items">
                <thead>
                    <tr>
                        <th class="col-ora">Ora</th>
                        <th class="col-cliente">Cliente</th>
                        <th class="col-zona">Zona</th>
                        <th class="col-contatti">Contatti</th>
                        <th class="col-note">Note e richiesta</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($ofTheDay as $request)
                    @php($customer = $request->customer)
                    <tr>
                        <td>{{ $request->appointment_at->format('H:i') }}</td>
                        <td>
                            <strong>{{ \App\Support\DisplayName::titleCase($customer?->full_name) ?: '—' }}</strong>
                            <div class="muted">{{ $request->number }}</div>
                        </td>
                        <td>
                            {{ $customer?->city ?: '—' }}@if($customer?->province) ({{ $customer->province }})@endif
                            @if($customer?->street)
                                <div class="muted">{{ $customer->street }}</div>
                            @endif
                        </td>
                        <td>
                            @if($customer?->primaryPhone())<div>{{ $customer->primaryPhone() }}</div>@endif
                            @if($customer?->primaryEmail())<div class="muted">{{ $customer->primaryEmail() }}</div>@endif
                            @if(! $customer?->primaryPhone() && ! $customer?->primaryEmail())—@endif
                        </td>
                        <td>
                            @if($request->appointment_notes)<div>{{ $request->appointment_notes }}</div>@endif
                            @if($request->request_details)<div class="muted">{{ \Illuminate\Support\Str::limit($request->request_details, 180) }}</div>@endif
                            @if($request->products->isNotEmpty())
                                <div class="muted">Interesse: {{ $request->products->pluck('name')->implode(', ') }}</div>
                            @endif
                            @if(! $request->appointment_notes && ! $request->request_details && $request->products->isEmpty())—@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    @endif

    <div class="footer-note">{{ $tenant?->legal_name ?: $tenant?->name }} &mdash; Generato il {{ now()->format('d/m/Y \a\l\l\e H:i') }}</div>
    @include('pdf.partials.page-numbers')
</body>
</html>
