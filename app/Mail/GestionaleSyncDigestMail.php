<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GestionaleSyncDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array{autofilled: array, diffs: array, customerLinks: array, productLinks: array, machineUnitLinks: array, newMachines: array} $results
     */
    public function __construct(
        public Tenant $tenant,
        public array $results,
    ) {}

    public function envelope(): Envelope
    {
        $total = count($this->results['autofilled']) + count($this->results['diffs'])
            + count($this->results['customerLinks']) + count($this->results['productLinks'])
            + count($this->results['machineUnitLinks']) + count($this->results['newMachines']);

        return new Envelope(
            subject: "Sync Eureka {$this->tenant->name}: {$total} righe da controllare",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.gestionale-sync-digest',
            with: [
                'tenant' => $this->tenant,
                'autofilled' => $this->results['autofilled'],
                'diffs' => $this->results['diffs'],
                'customerLinks' => $this->results['customerLinks'],
                'productLinks' => $this->results['productLinks'],
                'machineUnitLinks' => $this->results['machineUnitLinks'],
                'newMachines' => $this->results['newMachines'],
            ],
        );
    }
}
