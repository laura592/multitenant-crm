<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewLeaveRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveRequest $leaveRequest,
    ) {}

    public function envelope(): Envelope
    {
        $type = match ($this->leaveRequest->type) {
            LeaveRequest::TYPE_FERIE => 'ferie',
            LeaveRequest::TYPE_PERMESSO => 'permesso',
            LeaveRequest::TYPE_MALATTIA => 'malattia',
            default => $this->leaveRequest->type,
        };

        return new Envelope(
            subject: "Nuova richiesta {$type}: {$this->leaveRequest->user?->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.new-leave-request',
            with: ['leaveRequest' => $this->leaveRequest],
        );
    }
}
