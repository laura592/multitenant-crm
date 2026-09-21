<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public string $pdfContent,
        public ?string $customMessage = null,
        // L'offerta caffe' allegata allo stesso invio, se richiesta.
        public ?string $offertaCaffePdf = null,
        public ?string $offertaCaffeNomeFile = null,
        // Il link alla pagina dove il cliente accetta/rifiuta/chiede
        // (QuoteClientController); null = mail senza risposta online.
        public ?string $clientUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Preventivo {$this->quote->number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote',
            with: [
                'quote' => $this->quote,
                'customMessage' => $this->customMessage,
                'clientUrl' => $this->clientUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return array_values(array_filter([
            Attachment::fromData(fn () => $this->pdfContent, "preventivo-{$this->quote->number}.pdf")
                ->withMime('application/pdf'),
            $this->offertaCaffePdf !== null
                ? Attachment::fromData(fn () => $this->offertaCaffePdf, $this->offertaCaffeNomeFile ?? 'offerta-caffe.pdf')->withMime('application/pdf')
                : null,
        ]));
    }
}
