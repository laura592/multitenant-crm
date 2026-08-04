<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeadlineDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public Collection $deadlines,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Scadenzario {$this->tenant->name}: {$this->deadlines->count()} scadenze da controllare",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.deadline-digest',
            with: [
                'tenant' => $this->tenant,
                'deadlines' => $this->deadlines,
            ],
        );
    }
}
