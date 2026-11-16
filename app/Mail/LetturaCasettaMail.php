<?php

namespace App\Mail;

use App\Models\LetturaCasetta;
use App\Support\Casette\RendicontoLetturaPdf;
use App\Support\DisplayName;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Il rendiconto di una lettura della casetta dell'acqua, in PDF. */
class LetturaCasettaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LetturaCasetta $lettura,
        public string $pdfContent,
        public ?string $customMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: RendicontoLetturaPdf::oggetto($this->lettura));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.lettura-casetta',
            with: [
                'lettura' => $this->lettura,
                'customMessage' => $this->customMessage,
            ],
        );
    }

    /**
     * Il testo che parte quando non se ne scrive uno a mano.
     *
     * Sta qui e non dentro il blade perche' il modale di invio lo mette
     * nell'editor gia' scritto: se restasse un @else della vista, nel
     * modale il testo sembrerebbe mancare e non si potrebbe correggere
     * una riga senza riscriverlo tutto (Laura, 16/11/2026).
     */
    public static function testoStandard(LetturaCasetta $lettura): string
    {
        $casetta = $lettura->machineUnit?->serial_number;
        $comune = DisplayName::titleCase($lettura->customer?->company_name);

        $dove = implode('', [
            $casetta ? ' sulla casetta '.e($casetta) : '',
            $comune ? ' di '.e($comune) : '',
        ]);

        return implode('', [
            '<p>Buongiorno,</p>',
            '<p>in allegato il rendiconto della lettura del '.e($lettura->data->format('d/m/Y')).$dove.
                ', con le foto dei display.</p>',
        ]);
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, RendicontoLetturaPdf::nomeFile($this->lettura))
                ->withMime('application/pdf'),
        ];
    }
}
