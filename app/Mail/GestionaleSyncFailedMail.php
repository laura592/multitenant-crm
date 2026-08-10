<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A differenza di GestionaleSyncDigestMail (un riepilogo di proposte da
 * confermare), questa segnala che il controllo automatico con Eureka non e'
 * proprio riuscito a partire — senza un avviso distinto, un'interruzione di
 * Eureka per l'intera durata della sync produce silenziosamente lo stesso
 * "niente da segnalare" di una notte davvero senza novita' (vedi
 * GestionaleSyncRunner::run()['eurekaUnreachable']).
 */
class GestionaleSyncFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sync Eureka {$this->tenant->name}: controllo automatico non riuscito",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.gestionale-sync-failed',
            with: ['tenant' => $this->tenant],
        );
    }
}
