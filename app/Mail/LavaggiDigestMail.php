<?php

namespace App\Mail;

use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LavaggiDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, MaintenanceSchedule>  $schedules
     */
    public function __construct(
        public Tenant $tenant,
        public Collection $schedules,
        public int $days,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Lavaggi {$this->tenant->name}: {$this->schedules->count()} impianti da lavare",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.lavaggi-digest',
            with: [
                'tenant' => $this->tenant,
                'schedules' => $this->schedules,
                'days' => $this->days,
            ],
        );
    }
}
