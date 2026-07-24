<?php

namespace App\Mail;

use App\Models\Quote;
use App\Models\QuoteGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class QuoteGroupMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Quote>  $quotes
     * @param  array<string, string>  $pdfContents
     */
    public function __construct(
        public QuoteGroup $group,
        public Collection $quotes,
        public array $pdfContents,
        public ?string $emailBody = null,
        public ?string $subjectText = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Offerta {$this->group->number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote-group',
            with: [
                'group' => $this->group,
                'quotes' => $this->quotes,
                'emailBody' => $this->emailBody,
                'subjectText' => $this->subjectText,
            ],
        );
    }

    public function attachments(): array
    {
        return $this->quotes->map(function (Quote $quote) {
            return Attachment::fromData(
                fn () => $this->pdfContents[$quote->id] ?? '',
                "preventivo-{$quote->number}.pdf"
            )->withMime('application/pdf');
        })->all();
    }
}
