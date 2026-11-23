<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class DeadlineDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, \App\Models\Deadline>  $deadlines  scadenze urgenti o scadute
     * @param  Collection<int, \App\Models\Vehicle>  $mezziIncompleti  mezzi a cui manca un documento obbligatorio
     */
    public function __construct(
        public Tenant $tenant,
        public Collection $deadlines,
        public Collection $mezziIncompleti = new Collection,
    ) {}

    /**
     * Le due liste viaggiano nella stessa mail settimanale, quindi
     * l'oggetto deve dire quale delle due c'e' dentro: una mail intitolata
     * "3 scadenze da controllare" che invece parla di mezzi senza
     * assicurazione viene archiviata senza aprirla.
     */
    public function envelope(): Envelope
    {
        $pezzi = [];

        if ($this->deadlines->isNotEmpty()) {
            $pezzi[] = $this->deadlines->count().' scadenze da controllare';
        }

        if ($this->mezziIncompleti->isNotEmpty()) {
            $pezzi[] = $this->mezziIncompleti->count() === 1
                ? '1 mezzo senza documenti in corso'
                : $this->mezziIncompleti->count().' mezzi senza documenti in corso';
        }

        $coda = $pezzi === [] ? 'nessuna scadenza da controllare' : implode(' e ', $pezzi);

        return new Envelope(subject: "Scadenzario {$this->tenant->name}: {$coda}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.deadline-digest',
            with: [
                'tenant' => $this->tenant,
                'deadlines' => $this->deadlines,
                'mezziIncompleti' => $this->mezziIncompleti,
            ],
        );
    }
}
