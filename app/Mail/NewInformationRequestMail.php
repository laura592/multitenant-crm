<?php

namespace App\Mail;

use App\Models\InformationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewInformationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public InformationRequest $informationRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nuova richiesta informazioni {$this->informationRequest->number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.new-information-request',
            with: ['informationRequest' => $this->informationRequest],
        );
    }
}
