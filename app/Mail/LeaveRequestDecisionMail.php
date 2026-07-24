<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveRequest $leaveRequest,
    ) {}

    public function envelope(): Envelope
    {
        $type = match ($this->leaveRequest->type) {
            LeaveRequest::TYPE_FERIE => 'Ferie',
            LeaveRequest::TYPE_PERMESSO => 'Permesso',
            LeaveRequest::TYPE_MALATTIA => 'Malattia',
            default => ucfirst($this->leaveRequest->type),
        };

        $decision = $this->leaveRequest->status === 'approvato' ? 'approvata' : 'rifiutata';

        return new Envelope(
            subject: "Richiesta {$type} {$decision}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.leave-request-decision',
            with: ['leaveRequest' => $this->leaveRequest],
        );
    }
}
