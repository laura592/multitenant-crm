<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerGestionaleReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Da controllare su Eureka: {$this->customer->full_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.customer-gestionale-review',
            with: ['customer' => $this->customer, 'reason' => $this->reason],
        );
    }
}
