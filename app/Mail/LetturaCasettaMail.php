<?php

namespace App\Mail;

use App\Models\LetturaCasetta;
use App\Support\Casette\RendicontoLetturaPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Il rendiconto di una lettura della casetta dell'acqua, in PDF. */
class LetturaCasettaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LetturaCasetta $lettura,
        public string $pdfContent,
        public ?string $customMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: RendicontoLetturaPdf::oggetto($this->lettura));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.lettura-casetta',
            with: [
                'lettura' => $this->lettura,
                'customMessage' => $this->customMessage,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, RendicontoLetturaPdf::nomeFile($this->lettura))
                ->withMime('application/pdf'),
        ];
    }
}
