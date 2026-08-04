<?php

namespace App\Console\Commands;

use App\Mail\GestionaleSyncDigestMail;
use App\Models\Tenant;
use App\Support\Gestionale\GestionaleSyncRunner;
use Illuminate\Console\Command;
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

            $total = count($results['autofilled']) + count($results['diffs'])
                + count($results['customerLinks']) + count($results['productLinks'])
                + count($results['machineUnitLinks']) + count($results['newMachines']);

            $this->info("{$tenant->name}: {$total} righe da controllare.");

            if ($total === 0) {
                continue;
            }

            $recipients = $tenant->notificationRecipients('customer_gestionale');

            if (empty($recipients)) {
                continue;
            }

            Mail::to($recipients)->send(new GestionaleSyncDigestMail($tenant, $results));
            $sent++;
        }

        $this->info("Digest sync Eureka inviato a {$sent} tenant.");

        return self::SUCCESS;
    }
}
