<?php

namespace App\Console\Commands;

use App\Mail\LavaggiDigestMail;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Digest settimanale dei piani di LAVAGGIO scaduti o in scadenza, inviato ai
 * destinatari configurati in Notifiche > Lavaggi da programmare
 * (Tenant::notify_lavaggio_emails). Gemello di SendDeadlineReminders, ma su
 * MaintenanceSchedule invece che sullo scadenzario: qui non c'e' niente da
 * "segnare come fatto", il piano esce dall'elenco da solo quando viene
 * registrato un lavaggio e recalculateLavaggioNextDue() sposta next_due_date.
 *
 * Solo type=lavaggio: i piani di manutenzione ordinaria hanno un giro loro
 * (rapportini) e mescolarli renderebbe l'elenco inutilizzabile per chi
 * programma i lavaggi.
 */
class SendLavaggiReminders extends Command
{
    /**
     * 7 giorni e non i 30 del filtro "In scadenza" della tabella: la cadenza
     * tipica di un impianto birra e' 30 giorni, quindi una finestra di 30
     * giorni conterrebbe praticamente ogni piano attivo ad ogni invio (27 su
     * 29 con data, al 2026-09-01) e la mail smetterebbe di dire qualcosa. Con
     * 7 giorni ogni digest settimanale copre esattamente la settimana da
     * organizzare, piu' tutto l'arretrato scaduto.
     */
    protected $signature = 'lavaggi:send-reminders {--days=7 : Giorni di anticipo con cui segnalare un lavaggio in scadenza}';

    protected $description = 'Invia il digest settimanale dei lavaggi scaduti o in scadenza';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));

        $tenants = Tenant::query()->where('is_active', true)->get();

        $sent = 0;

        foreach ($tenants as $tenant) {
            $recipients = $tenant->notificationRecipients('lavaggio');

            if (empty($recipients)) {
                continue;
            }

            $schedules = MaintenanceSchedule::query()
                ->where('tenant_id', $tenant->id)
                ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
                ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
                // I piani "a chiamata" non hanno una scadenza (next_due_date
                // nullo, vedi recalculateLavaggioNextDue): non sono in ritardo,
                // aspettano una telefonata del cliente.
                ->whereNotNull('next_due_date')
                ->whereDate('next_due_date', '<=', now()->addDays($days))
                ->with(['customer', 'lastLavaggio'])
                ->orderBy('next_due_date')
                ->get();

            if ($schedules->isEmpty()) {
                continue;
            }

            Mail::to($recipients)->send(new LavaggiDigestMail($tenant, $schedules, $days));
            $sent++;

            $this->line("{$tenant->name}: {$schedules->count()} lavaggi segnalati a ".implode(', ', $recipients));
        }

        $this->info("Digest lavaggi inviato a {$sent} tenant.");

        return self::SUCCESS;
    }
}
