<?php

namespace App\Mail;

use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\QuoteResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Al cliente: ricevuta di cio' che ha fatto dalla pagina del preventivo.
 * Sull'accettazione allega il PDF esatto che ha firmato.
 */
class QuoteClientConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote|QuoteGroup $document,
        public QuoteResponse $response,
    ) {}

    public function envelope(): Envelope
    {
        $number = $this->response->quote?->number ?? $this->document->number;

        return new Envelope(
            subject: match ($this->response->type) {
                QuoteResponse::TYPE_ACCEPTED => "Conferma accettazione preventivo {$number}",
                QuoteResponse::TYPE_CALLBACK => "La richiameremo – preventivo {$number}",
                default => "Abbiamo ricevuto la sua risposta – preventivo {$number}",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote-client-confirmation',
            with: [
                'document' => $this->document,
                'response' => $this->response,
            ],
        );
    }

    public function attachments(): array
    {
        $path = $this->response->accepted_pdf_path;

        if ($this->response->type !== QuoteResponse::TYPE_ACCEPTED || ! $path || ! Storage::disk('local')->exists($path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $path)
                ->as("preventivo-{$this->response->quote?->number}-firmato.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
