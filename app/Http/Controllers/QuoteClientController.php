<?php

namespace App\Http\Controllers;

use App\Filament\Resources\QuoteResource;
use App\Mail\QuoteClientConfirmationMail;
use App\Mail\QuoteClientResponseMail;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\QuoteResponse;
use App\Models\User;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * La pagina che il cliente apre dal link nella mail del preventivo (o
 * dell'offerta globale): lo rivede, e lo accetta firmando, lo rifiuta, fa
 * una domanda o chiede di essere richiamato. Pubblica, senza login: chi ha
 * il link (48 caratteri casuali, vedi HasClientLink) e' il cliente.
 */
class QuoteClientController extends Controller
{
    /** Stesso tetto di SignaturePad: una firma su canvas sta ben sotto. */
    private const MAX_SIGNATURE_BASE64 = 2 * 1024 * 1024;

    public function show(Request $request, string $token): View
    {
        $document = $this->resolve($token);
        $quotes = $this->quotesOf($document);

        // Chi e' collegato al pannello e apre il link (per provarlo, o dalla
        // copia in CC) non e' il cliente: non conta come visita.
        if (! auth()->check()) {
            $document->recordClientView();

            if ($document instanceof QuoteGroup) {
                $quotes->each->recordClientView();
            }
        }

        $accepted = $quotes->firstWhere('status', 'accettato');

        return view('client.quote', [
            'token' => $token,
            'document' => $document,
            'isGroup' => $document instanceof QuoteGroup,
            'quotes' => $quotes,
            'tenant' => $document->tenant,
            'customer' => $document->customer,
            'accepted' => $accepted,
            'acceptance' => $accepted
                ? QuoteResponse::withoutGlobalScope('tenant')->where('quote_id', $accepted->id)->where('type', QuoteResponse::TYPE_ACCEPTED)->latest()->first()
                : null,
            'allRejected' => $quotes->isNotEmpty() && $quotes->every(fn (Quote $q) => $q->status === 'rifiutato'),
            'openQuotes' => $quotes->reject(fn (Quote $q) => $q->isDecided())->values(),
            'knownPhone' => $document->customer?->primaryPhone(),
            // Le domande gia' fatte restano visibili: il cliente vede che sono
            // arrivate e puo' aggiungerne altre.
            'questions' => QuoteResponse::withoutGlobalScope('tenant')
                ->where($document instanceof QuoteGroup ? 'quote_group_id' : 'quote_id', $document->id)
                ->where('type', QuoteResponse::TYPE_QUESTION)
                ->oldest()
                ->get(),
            'initialAction' => in_array($request->query('azione'), ['accetta', 'rifiuta', 'domanda', 'richiamata'], true) ? $request->query('azione') : null,
        ]);
    }

    public function pdf(string $token, string $quoteId)
    {
        $quote = $this->quotesOf($this->resolve($token))->firstWhere('id', $quoteId);

        abort_unless($quote, 404);

        return QuoteResource::buildPdf($quote)->stream("preventivo-{$quote->number}.pdf");
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $document = $this->resolve($token);
        $quotes = $this->quotesOf($document);

        $type = $request->validate([
            'type' => ['required', Rule::in([
                QuoteResponse::TYPE_ACCEPTED,
                QuoteResponse::TYPE_REJECTED,
                QuoteResponse::TYPE_QUESTION,
                QuoteResponse::TYPE_CALLBACK,
            ])],
        ])['type'];

        $response = match ($type) {
            QuoteResponse::TYPE_ACCEPTED => $this->accept($request, $document, $quotes),
            QuoteResponse::TYPE_REJECTED => $this->reject($request, $document, $quotes),
            QuoteResponse::TYPE_QUESTION => $this->question($request, $document),
            QuoteResponse::TYPE_CALLBACK => $this->callback($request, $document),
        };

        $this->notifyStaff($document, $response);

        if ($response->email) {
            try {
                Mail::to($response->email)->send(new QuoteClientConfirmationMail($document, $response));
            } catch (\Throwable $e) {
                // La risposta e' gia' registrata e l'ufficio avvisato: una
                // conferma al cliente non partita non deve fargli vedere un
                // errore come se non avesse risposto.
                report($e);
            }
        }

        return redirect()
            ->route('client.quote.show', ['token' => $token])
            ->with('client_response', $response->type);
    }

    protected function accept(Request $request, Quote|QuoteGroup $document, Collection $quotes): QuoteResponse
    {
        $data = $request->validate([
            'quote_id' => [$document instanceof QuoteGroup ? 'required' : 'nullable', 'string'],
            'signer_name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string'],
            'consent' => ['accepted'],
        ], [
            'signer_name.required' => 'Scriva nome e cognome di chi firma.',
            'signature.required' => 'Manca la firma: la tracci nel riquadro con il dito o con il mouse.',
            'consent.accepted' => 'Per accettare serve la conferma di aver letto il preventivo.',
            'quote_id.required' => 'Scelga quale soluzione accetta.',
        ]);

        $quote = $document instanceof QuoteGroup
            ? $quotes->firstWhere('id', $data['quote_id'])
            : $document;

        if (! $quote) {
            throw ValidationException::withMessages(['quote_id' => 'Scelga quale soluzione accetta.']);
        }

        if ($quotes->contains(fn (Quote $q) => $q->status === 'accettato') || $quote->isDecided()) {
            throw ValidationException::withMessages(['type' => 'Questo preventivo ha gia\' ricevuto una risposta. Per modifiche ci contatti.']);
        }

        $signature = $this->storeSignature($data['signature']);

        if (! $signature) {
            throw ValidationException::withMessages(['signature' => 'La firma non e\' stata letta correttamente: la ritracci e riprovi.']);
        }

        $response = DB::transaction(function () use ($request, $document, $quotes, $quote, $data, $signature) {
            $response = $this->record($request, $document, QuoteResponse::TYPE_ACCEPTED, [
                'quote_id' => $quote->id,
                'signer_name' => trim($data['signer_name']),
                'signature_path' => $signature,
            ]);

            // Passa da Quote::updated: cliente pronto per il gestionale,
            // offerta globale "Scelta", richiesta informazioni allineata.
            $quote->update(['status' => 'accettato']);

            // Le altre alternative della stessa offerta non sono state
            // scelte: restavano "Inviato" per sempre.
            $quotes->reject(fn (Quote $q) => $q->is($quote) || $q->isDecided())
                ->each(fn (Quote $q) => $q->update(['status' => 'rifiutato']));

            return $response;
        });

        // Il PDF firmato, come il cliente lo ha accettato: il preventivo
        // resta modificabile dal pannello, questa copia no.
        $pdf = QuoteResource::buildPdf($quote->fresh())->output();
        $pdfPath = 'quote-responses/pdf/'.$quote->number.'-accettato-'.now()->format('Ymd-His').'-'.Str::random(6).'.pdf';
        Storage::disk('local')->put($pdfPath, $pdf);

        $response->update([
            'accepted_pdf_path' => $pdfPath,
            'accepted_pdf_sha256' => hash('sha256', $pdf),
        ]);

        return $response;
    }

    protected function reject(Request $request, Quote|QuoteGroup $document, Collection $quotes): QuoteResponse
    {
        $data = $request->validate([
            // Facoltativo: un "no, grazie" senza spiegazioni vale piu' del silenzio.
            'reason' => ['nullable', Rule::in(array_keys(QuoteResponse::reasonLabels()))],
            'message' => ['nullable', 'string', 'max:3000'],
        ]);

        $open = $quotes->reject(fn (Quote $q) => $q->isDecided());

        if ($open->isEmpty() || $quotes->contains(fn (Quote $q) => $q->status === 'accettato')) {
            throw ValidationException::withMessages(['type' => 'Questo preventivo ha gia\' ricevuto una risposta. Per modifiche ci contatti.']);
        }

        return DB::transaction(function () use ($request, $document, $open, $data) {
            $response = $this->record($request, $document, QuoteResponse::TYPE_REJECTED, [
                'quote_id' => $document instanceof Quote ? $document->id : null,
                'reason' => $data['reason'] ?? null,
                'message' => $data['message'] ?? null,
            ]);

            $open->each(fn (Quote $q) => $q->update(['status' => 'rifiutato']));

            return $response;
        });
    }

    protected function question(Request $request, Quote|QuoteGroup $document): QuoteResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
            'phone' => ['nullable', 'string', 'max:50'],
        ], [
            'message.required' => 'Scriva la sua domanda o la modifica che vorrebbe.',
        ]);

        return $this->record($request, $document, QuoteResponse::TYPE_QUESTION, [
            'quote_id' => $document instanceof Quote ? $document->id : null,
            'message' => $data['message'],
            'phone' => $data['phone'] ?? null,
        ]);
    }

    protected function callback(Request $request, Quote|QuoteGroup $document): QuoteResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
            'preferred_time' => ['nullable', Rule::in(array_keys(QuoteResponse::preferredTimeLabels()))],
            'message' => ['nullable', 'string', 'max:3000'],
        ], [
            'phone.required' => 'Ci lasci un numero a cui richiamarla.',
        ]);

        return $this->record($request, $document, QuoteResponse::TYPE_CALLBACK, [
            'quote_id' => $document instanceof Quote ? $document->id : null,
            'phone' => $data['phone'],
            'preferred_time' => $data['preferred_time'] ?? null,
            'message' => $data['message'] ?? null,
        ]);
    }

    protected function record(Request $request, Quote|QuoteGroup $document, string $type, array $attributes): QuoteResponse
    {
        return QuoteResponse::create([
            // Al cliente non si chiede l'email: si risponde all'indirizzo a
            // cui gli e' stato mandato il preventivo.
            'email' => $this->knownEmail($document),
            'tenant_id' => $document->tenant_id,
            'quote_group_id' => $document instanceof QuoteGroup ? $document->id : $document->quote_group_id,
            'type' => $type,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            ...$attributes,
        ]);
    }

    /**
     * Salva la firma sul disco privato (non su public come quella dei
     * rapportini: e' un documento d'accettazione, non un'immagine da
     * mostrare). Controlli sui byte veri, come SignaturePad.
     */
    protected function storeSignature(string $dataUrl): ?string
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return null;
        }

        $data = substr($dataUrl, strlen('data:image/png;base64,'));

        if (strlen($data) > self::MAX_SIGNATURE_BASE64) {
            return null;
        }

        $decoded = base64_decode($data, strict: true);
        $info = $decoded === false ? false : @getimagesizefromstring($decoded);

        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            return null;
        }

        $path = 'quote-responses/signatures/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $decoded);

        return $path;
    }

    /**
     * Per mail a chi e' impostato in Impostazioni > Notifiche ("Risposte dei
     * clienti ai preventivi"); con la campanella del pannello anche a chi ha
     * inviato il preventivo.
     */
    protected function notifyStaff(Quote|QuoteGroup $document, QuoteResponse $response): void
    {
        $tenant = $document->tenant;
        $recipients = $tenant?->notificationRecipients('quote_response') ?? [];

        if ($recipients !== []) {
            try {
                Mail::to($recipients)->send(new QuoteClientResponseMail($document, $response));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $quote = $response->quote ?? ($document instanceof Quote ? $document : $document->quotes()->withoutGlobalScope('tenant')->orderBy('number')->first());
        $label = QuoteResponse::typeLabels()[$response->type];
        $customer = \App\Support\DisplayName::titleCase($document->customer?->company_name) ?: \App\Support\DisplayName::titleCase($document->customer?->full_name);

        foreach ($this->senders($document) as $user) {
            $notification = Notification::make()
                ->title("{$document->number}: {$label}")
                ->body($customer)
                ->icon(match ($response->type) {
                    QuoteResponse::TYPE_ACCEPTED => 'heroicon-o-check-badge',
                    QuoteResponse::TYPE_REJECTED => 'heroicon-o-x-circle',
                    QuoteResponse::TYPE_QUESTION => 'heroicon-o-chat-bubble-left-right',
                    default => 'heroicon-o-phone',
                })
                ->color(QuoteResponse::typeColors()[$response->type]);

            if ($quote && $tenant) {
                $notification->actions([
                    NotificationAction::make('open')
                        ->label('Apri preventivo')
                        ->url(QuoteResource::getUrl('view', ['record' => $quote], tenant: $tenant)),
                ]);
            }

            $notification->sendToDatabase($user);
        }
    }

    /**
     * Gli utenti che hanno mandato il documento al cliente (ultimo invio
     * riuscito per primo).
     *
     * @return Collection<int, User>
     */
    protected function senders(Quote|QuoteGroup $document): Collection
    {
        $userIds = $document->emails()
            ->where('status', 'sent')
            ->whereNotNull('user_id')
            ->reorder()
            ->latest()
            ->pluck('user_id')
            ->unique();

        return User::whereKey($userIds)->get();
    }

    protected function knownEmail(Quote|QuoteGroup $document): ?string
    {
        return $document->emails()->where('status', 'sent')->reorder()->latest()->value('recipient_email')
            ?? $document->customer?->primaryEmail();
    }

    protected function resolve(string $token): Quote|QuoteGroup
    {
        abort_if(strlen($token) < 32, 404);

        return Quote::withoutGlobalScope('tenant')->where('public_token', $token)->first()
            ?? QuoteGroup::withoutGlobalScope('tenant')->where('public_token', $token)->firstOrFail();
    }

    /**
     * @return Collection<int, Quote>
     */
    protected function quotesOf(Quote|QuoteGroup $document): Collection
    {
        $with = ['quoteProducts.product', 'quoteProducts.options.product', 'paymentMethodRelation'];

        if ($document instanceof Quote) {
            return collect([$document->load($with)]);
        }

        return $document->quotes()
            ->withoutGlobalScope('tenant')
            ->with($with)
            ->orderBy('number')
            ->get();
    }
}
