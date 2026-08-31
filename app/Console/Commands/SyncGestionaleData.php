<?php

namespace App\Console\Commands;

use App\Mail\GestionaleSyncDigestMail;
use App\Mail\GestionaleSyncFailedMail;
use App\Models\Tenant;
use App\Support\Gestionale\GestionaleSyncRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Confronta clienti/prodotti gia' collegati a Eureka con l'anagrafica reale
 * (autocompila i campi vuoti, segnala le differenze) e propone nuovi
 * collegamenti per chi non ne ha ancora uno — vedi GestionaleSyncRunner per
 * la logica, mai una scrittura verso Eureka ne' un'assegnazione automatica
 * di un nuovo collegamento (solo proposte da confermare a mano).
 *
 * Una sola email di riepilogo per tenant per esecuzione (non una per
 * cliente), stesso pattern di SendDeadlineReminders/DeadlineDigestMail.
 */
class SyncGestionaleData extends Command
{
    protected $signature = 'gestionale:sync';

    protected $description = 'Confronta clienti e prodotti collegati a Eureka, autocompila i campi vuoti e propone nuovi collegamenti';

    public function handle(): int
    {
        $tenants = Tenant::query()->where('is_active', true)->get()
            ->filter->hasGestionaleEurekaCredentials();

        $sent = 0;

        foreach ($tenants as $tenant) {
            $results = (new GestionaleSyncRunner($tenant))->run();

            // Ogni chiamata a Eureka fallita durante tutta la sync (host giu',
            // credenziali rifiutate, ecc.) produce comunque risultati vuoti da
            // GestionaleSyncRunner (best-effort by design) — indistinguibile
            // da "niente da segnalare" senza questo controllo, quindi
            // un'interruzione di Eureka passerebbe silenziosamente inosservata.
            if ($results['eurekaUnreachable']) {
                $this->warn("{$tenant->name}: Eureka irraggiungibile, sync saltato.");

                $failedRecipients = $tenant->notificationRecipients('gestionale_sync_failed');

                if (! empty($failedRecipients)) {
                    Mail::to($failedRecipients)->send(new GestionaleSyncFailedMail($tenant));
                    $sent++;
                }

                continue;
            }

            $total = count($results['autofilled']) + count($results['diffs'])
                + count($results['customerLinks']) + count($results['productLinks'])
                + count($results['machineUnitLinks']) + count($results['newMachines']);

            $this->info("{$tenant->name}: {$total} righe da controllare.");

            // Un endpoint che risponde sempre male (403 per diritti di modulo,
            // 404 per una rotta rimossa) mentre il resto di Eureka funziona:
            // le proposte che dipendono da quell'endpoint semplicemente non
            // arrivano piu'. Senza segnalarlo qui il sync direbbe "0 righe da
            // controllare" — la stessa frase di una notte tranquilla — ed e'
            // cosi' che il 403 su /crm_api/m14/search e' passato inosservato.
            foreach ($results['apiIssues'] as $issue) {
                $this->warn("{$tenant->name}: {$issue['endpoint']} ha risposto male {$issue['failures']} volte su {$issue['attempts']} ({$issue['statuses']}).");
            }

            if (! empty($results['apiIssues'])) {
                Log::warning('Endpoint Eureka in errore durante gestionale:sync', [
                    'tenant' => $tenant->slug,
                    'issues' => $results['apiIssues'],
                ]);
            }

            $digestRecipients = $tenant->notificationRecipients('gestionale_sync_digest');

            // Il digest parte anche con $total a zero se ci sono endpoint in
            // errore: e' proprio il caso in cui il silenzio ingannerebbe.
            if (($total === 0 && empty($results['apiIssues'])) || empty($digestRecipients)) {
                continue;
            }

            Mail::to($digestRecipients)->send(new GestionaleSyncDigestMail($tenant, $results));
            $sent++;
        }

        $this->info("Digest sync Eureka inviato a {$sent} tenant.");

        return self::SUCCESS;
    }
}
