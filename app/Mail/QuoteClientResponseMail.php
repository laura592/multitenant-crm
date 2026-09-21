<?php

namespace App\Mail;

use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\QuoteResponse;
use App\Support\DisplayName;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * All'ufficio: il cliente ha risposto dal link nella mail del preventivo.
 */
class QuoteClientResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote|QuoteGroup $document,
        public QuoteResponse $response,
    ) {}

    public function envelope(): Envelope
    {
        $customer = DisplayName::titleCase($this->document->customer?->company_name)
            ?: DisplayName::titleCase($this->document->customer?->full_name);

        return new Envelope(
            // "Rispondi" dalla mail scrive direttamente al cliente.
            replyTo: $this->response->email ? [$this->response->email] : [],
            subject: match ($this->response->type) {
                QuoteResponse::TYPE_ACCEPTED => "✅ Accettato: {$this->response->quote?->number} – {$customer}",
                QuoteResponse::TYPE_REJECTED => "Rifiutato: {$this->document->number} – {$customer}",
                QuoteResponse::TYPE_QUESTION => "Domanda sul preventivo {$this->document->number} – {$customer}",
                default => "Da richiamare: {$customer} ({$this->document->number})",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote-client-response',
            with: [
                'document' => $this->document,
                'response' => $this->response,
            ],
        );
    }

    /**
     * Sull'accettazione l'ufficio riceve lo stesso PDF firmato del cliente.
     */
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
