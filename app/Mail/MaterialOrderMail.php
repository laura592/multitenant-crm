<?php

namespace App\Mail;

use App\Models\MaterialOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MaterialOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MaterialOrder $order,
        public string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Ordine materiali {$this->order->number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.material-order',
            with: ['order' => $this->order],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, "{$this->order->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
