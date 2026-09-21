@php
    use App\Models\QuoteResponse;
    /** @var \App\Models\Quote $record */
    $record = $getRecord();
    $link = $record->quoteGroup ?? $record;
    $viewer = $record->client_view_count ? $record : ($record->quoteGroup?->client_view_count ? $record->quoteGroup : null);
    $responses = $record->clientResponses();
    $tones = [
        QuoteResponse::TYPE_ACCEPTED => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-400',
        QuoteResponse::TYPE_REJECTED => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400',
        QuoteResponse::TYPE_QUESTION => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-400',
        QuoteResponse::TYPE_CALLBACK => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400',
    ];
@endphp
<div class="space-y-3 text-sm">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-gray-600 dark:text-gray-300">
        @if($viewer)
            <span>👁 Aperto dal cliente <strong>{{ $viewer->client_view_count }} {{ $viewer->client_view_count === 1 ? 'volta' : 'volte' }}</strong>, l'ultima il {{ $viewer->client_last_viewed_at?->format('d/m/Y H:i') }}</span>
        @elseif($link->public_token)
            <span>Il cliente non ha ancora aperto il link.</span>
        @else
            <span>Link cliente non ancora inviato.</span>
        @endif
    </div>

    @foreach($responses as $response)
        <div class="rounded-xl border border-gray-200 p-3 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $tones[$response->type] ?? '' }}">
                    {{ QuoteResponse::typeLabels()[$response->type] ?? $response->type }}
                </span>
                <span class="text-xs text-gray-500">{{ $response->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <dl class="mt-2 grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1">
                @if($response->quote && $response->quote->isNot($record))
                    <dt class="text-gray-500">Soluzione</dt><dd>{{ $response->quote->number }}</dd>
                @endif
                @if($response->signer_name)
                    <dt class="text-gray-500">Firmato da</dt><dd class="font-medium">{{ $response->signer_name }}</dd>
                @endif
                @if($response->reason)
                    <dt class="text-gray-500">Motivo</dt><dd>{{ QuoteResponse::reasonLabels()[$response->reason] ?? $response->reason }}</dd>
                @endif
                @if($response->phone)
                    <dt class="text-gray-500">Telefono</dt><dd><a class="text-primary-600 underline" href="tel:{{ preg_replace('/[^0-9+]/', '', $response->phone) }}">{{ $response->phone }}</a>@if($response->preferred_time) · {{ QuoteResponse::preferredTimeLabels()[$response->preferred_time] ?? '' }}@endif</dd>
                @endif
                @if($response->email)
                    <dt class="text-gray-500">Email</dt><dd><a class="text-primary-600 underline" href="mailto:{{ $response->email }}">{{ $response->email }}</a></dd>
                @endif
            </dl>
            @if($response->message)
                <p class="mt-2 whitespace-pre-line rounded-lg bg-gray-50 p-2 dark:bg-white/5">{{ $response->message }}</p>
            @endif
            @if($response->signature_path)
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <img src="{{ route('quote-responses.file', [$response, 'firma']) }}" alt="Firma" class="h-16 rounded border border-gray-200 bg-white p-1">
                    @if($response->accepted_pdf_path)
                        <a href="{{ route('quote-responses.file', [$response, 'pdf']) }}" target="_blank" class="text-primary-600 underline">PDF accettato</a>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
