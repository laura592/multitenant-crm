<x-mail::message>
@php
	use App\Models\QuoteResponse;
	$tenant = $document->tenant;
	$company = $tenant?->legal_name ?: ($tenant?->name ?: config('app.name'));
	$number = $response->quote?->number ?? $document->number;
	$title = match ($response->type) {
		QuoteResponse::TYPE_ACCEPTED => 'Grazie, il preventivo è accettato',
		QuoteResponse::TYPE_REJECTED => 'Grazie per averci risposto',
		QuoteResponse::TYPE_QUESTION => 'Abbiamo ricevuto la sua domanda',
		default => 'La richiameremo al più presto',
	};
@endphp

<x-mail.hero :kicker="'Preventivo '.$number" :title="$title" />

<x-mail.box>
@switch($response->type)
@case(QuoteResponse::TYPE_ACCEPTED)
<p>Gentile {{ $response->signer_name }},</p>
<p>abbiamo ricevuto l'accettazione del preventivo <strong>{{ $number }}</strong> il {{ $response->created_at?->timezone(config('app.timezone'))->format('d/m/Y \a\l\l\e H:i') }}.</p>
<p>In allegato trova il preventivo con la sua firma. Un nostro incaricato la contatterà a breve per organizzare tutto.</p>
@break
@case(QuoteResponse::TYPE_REJECTED)
<p>Gentile cliente,</p>
<p>ci dispiace che la proposta non faccia al caso suo: grazie per averci fatto sapere il motivo, ci aiuta a migliorare.</p>
<p>Se in futuro le servisse qualcosa, siamo qui.</p>
@break
@case(QuoteResponse::TYPE_QUESTION)
<p>Gentile cliente,</p>
<p>abbiamo ricevuto il suo messaggio e le risponderemo il prima possibile.</p>
<div style="margin-top:10px;padding:12px;background:#f8fafc;border-left:3px solid #316EB4;border-radius:6px;white-space:pre-line;">{{ $response->message }}</div>
@break
@default
<p>Gentile cliente,</p>
<p>la richiameremo al numero <strong>{{ $response->phone }}</strong>@if($response->preferred_time && $response->preferred_time !== 'indifferente') {{ strtolower(QuoteResponse::preferredTimeLabels()[$response->preferred_time] ?? '') }}@endif.</p>
@endswitch
<p style="margin-top:14px;">Cordiali saluti,<br>{{ $company }}</p>
</x-mail.box>

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
