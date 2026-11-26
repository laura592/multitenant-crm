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
        /**
         * Il PDF del preventivo, se si allega.
         *
         * Null vuol dire mail col solo link: il documento sta sulla pagina
         * del cliente, che lo puo' scaricare da li'. E' l'unico modo per
         * sapere se l'ha aperto — con l'allegato il link non lo apriva
         * nessuno e client_view_count restava a zero su tutti i preventivi.
         */
        public ?string $pdfContent,
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
                'conAllegato' => $this->pdfContent !== null,
            ],
        );
    }

    public function attachments(): array
    {
        return array_values(array_filter([
            $this->pdfContent !== null
                ? Attachment::fromData(fn () => $this->pdfContent, "preventivo-{$this->quote->number}.pdf")
                    ->withMime('application/pdf')
                : null,
            $this->offertaCaffePdf !== null
                ? Attachment::fromData(fn () => $this->offertaCaffePdf, $this->offertaCaffeNomeFile ?? 'offerta-caffe.pdf')->withMime('application/pdf')
                : null,
        ]));
    }
}
