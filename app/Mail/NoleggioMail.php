<?php

namespace App\Mail;

use App\Models\Noleggio;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Il contratto di noleggio operativo mandato al cliente da firmare. */
class NoleggioMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Noleggio $noleggio,
        public string $pdfContent,
        public string $nomeFile,
        public ?string $customMessage = null,
        public ?string $subjectText = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText ?: 'Contratto di noleggio operativo');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.noleggio',
            with: [
                'noleggio' => $this->noleggio,
                'cliente' => $this->noleggio->customer,
                'customMessage' => $this->customMessage,
                'subjectText' => $this->subjectText,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, $this->nomeFile)->withMime('application/pdf'),
        ];
    }
}
