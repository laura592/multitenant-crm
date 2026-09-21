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
        // L'offerta caffe' allegata allo stesso invio, se richiesta.
        public ?string $offertaCaffePdf = null,
        public ?string $offertaCaffeNomeFile = null,
        // Vedi QuoteMail::$clientUrl.
        public ?string $clientUrl = null,
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
            text: 'mail.quote-group-text',
            with: [
                'group' => $this->group,
                'quotes' => $this->quotes,
                'emailBody' => $this->emailBody,
                'subjectText' => $this->subjectText,
                'clientUrl' => $this->clientUrl,
            ],
        );
    }

    public function attachments(): array
    {
        $allegati = $this->quotes->map(function (Quote $quote) {
            return Attachment::fromData(
                fn () => $this->pdfContents[$quote->id] ?? '',
                "preventivo-{$quote->number}.pdf"
            )->withMime('application/pdf');
        })->all();

        if ($this->offertaCaffePdf !== null) {
            $allegati[] = Attachment::fromData(fn () => $this->offertaCaffePdf, $this->offertaCaffeNomeFile ?? 'offerta-caffe.pdf')
                ->withMime('application/pdf');
        }

        return $allegati;
    }
}
