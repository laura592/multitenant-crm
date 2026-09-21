<?php

namespace App\Mail;

use App\Models\OffertaCaffe;
use App\Support\Pdf\OffertaCaffePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** L'offerta caffe' mandata da sola, senza un preventivo. */
class OffertaCaffeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public OffertaCaffe $offerta,
        public string $pdfContent,
        public ?string $customMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Offerta caffè {$this->offerta->number}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.offerta-caffe',
            with: [
                'cliente' => $this->offerta->customer,
                'offerta' => $this->offerta,
                'customMessage' => $this->customMessage,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, OffertaCaffePdf::nomeFile($this->offerta))->withMime('application/pdf'),
        ];
    }
}
