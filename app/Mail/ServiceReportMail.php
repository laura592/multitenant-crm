<?php

namespace App\Mail;

use App\Models\ServiceReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceReport $report,
        public string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Rapportino di intervento {$this->report->number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.service-report',
            with: ['report' => $this->report],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, "rapportino-{$this->report->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
