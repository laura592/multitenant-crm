<?php

namespace App\Console\Commands;

use App\Mail\DeadlineDigestMail;
use App\Models\Deadline;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Digest settimanale delle scadenze urgenti/scadute per tenant, inviato ai
 * destinatari configurati in Notifiche > Scadenze (Tenant::notify_deadline_
 * emails). Scadenza urgente = Deadline::isUrgent(): finche' resta "attiva"
 * ed entro la finestra reminder_days_before (o gia' scaduta), viene
 * ripresentata ogni settimana - si ferma da sola quando viene rinnovata o
 * segnata come pagata.
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'deadlines:send-reminders';

    protected $description = 'Invia il digest settimanale delle scadenze in avvicinamento o scadute';

    public function handle(): int
    {
        $tenants = Tenant::query()->where('is_active', true)->get();

        $sent = 0;

        foreach ($tenants as $tenant) {
            $recipients = $tenant->notificationRecipients('deadline');

            if (empty($recipients)) {
                continue;
            }

            $deadlines = Deadline::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', Deadline::STATUS_ATTIVA)
                ->with('deadlinable')
                ->orderBy('due_date')
                ->get()
                ->filter->isUrgent();

            if ($deadlines->isEmpty()) {
                continue;
            }

            Mail::to($recipients)->send(new DeadlineDigestMail($tenant, $deadlines));
            $sent++;
        }

        $this->info("Digest scadenze inviato a {$sent} tenant.");

        return self::SUCCESS;
    }
}
