<?php

namespace App\Console\Commands;

use App\Mail\DeadlineDigestMail;
use App\Models\Deadline;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Digest settimanale delle scadenze urgenti/scadute per tenant, inviato ai
 * destinatari configurati in Notifiche > Scadenze (Tenant::notify_deadline_
 * emails). Scadenza urgente = Deadline::isUrgent(): finche' resta "attiva"
 * ed entro la finestra reminder_days_before (o gia' scaduta), viene
 * ripresentata ogni settimana - si ferma da sola quando viene rinnovata o
 * segnata come pagata.
 *
 * Nella stessa mail viaggiano i MEZZI SENZA DOCUMENTI IN CORSO
 * (Vehicle::scopeConScadenzeMancanti). Sono il rovescio del digest: il
 * resto dell'elenco ricorda le scadenze che ci sono, questo blocco segnala
 * quelle che non sono mai state inserite, e che quindi non possono scadere
 * ne' comparire fra le urgenti. Una seconda mail settimanale avrebbe
 * diviso in due posti la stessa domanda ("cosa devo sistemare sui mezzi?"),
 * quindi sta qui (Laura, 23/11/2026).
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'deadlines:send-reminders {--dry-run : stampa cosa manderebbe senza mandare niente}';

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

            // L'eager load non e' cosmetico: scadenzeMancanti() legge due
            // relazioni per ogni mezzo (scadenze ed esenzioni), e senza sono
            // due query per ogni mezzo del parco.
            $mezziIncompleti = Vehicle::query()
                ->where('tenant_id', $tenant->id)
                ->conScadenzeMancanti()
                ->with(['deadlines', 'esenzioniScadenze'])
                ->orderBy('plate')
                ->get();

            if ($deadlines->isEmpty() && $mezziIncompleti->isEmpty()) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("[{$tenant->name}] -> ".implode(', ', $recipients));
                $this->line("  scadenze urgenti: {$deadlines->count()}");
                foreach ($deadlines as $d) {
                    $this->line('    - '.(Deadline::typeLabels()[$d->type] ?? $d->type)
                        .' '.$d->due_date->format('d/m/Y').' — '.$d->relatedLabel());
                }
                $this->line("  mezzi senza documenti in corso: {$mezziIncompleti->count()}");
                foreach ($mezziIncompleti as $v) {
                    $this->line('    - '.$v->plate.' '.$v->descrizione()
                        .' — manca: '.implode(', ', $v->etichetteScadenzeMancanti()));
                }

                continue;
            }

            Mail::to($recipients)->send(new DeadlineDigestMail($tenant, $deadlines, $mezziIncompleti));
            $sent++;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nessuna mail inviata.');

            return self::SUCCESS;
        }

        $this->info("Digest scadenze inviato a {$sent} tenant.");

        return self::SUCCESS;
    }
}
