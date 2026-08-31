<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Associa il cliente a se stesso come pagante, dove Eureka dice che paga per se'.
 *
 * E' il caso che mancava: ApplyEurekaMachineBillingPayer sa scrivere un
 * pagante terzo, ma quando Eureka dice "paga il cliente stesso" si limita a
 * contarlo come "nessuna modifica necessaria" — e se in CRM era rimasto un
 * pagante vecchio, quello resta. Risultato: macchine fatturate a un
 * torrefattore che secondo il gestionale non c'entra piu' (trovato dal vivo
 * su matricola 1400700 / La Dolce Vita, 2026-08-31).
 *
 * SCRIVE il cliente stesso, non azzera. La differenza conta: NULL e'
 * ambiguo — puo' voler dire "paga per se'" oppure "nessuno l'ha ancora
 * guardato". Un'autoreferenza esplicita dice "verificato su Eureka: paga
 * lui". invoiceRecipient() restituisce lo stesso risultato in entrambi i
 * casi ($this->billingCustomer ?? $this), quindi non cambia niente a valle.
 *
 * Fonte per le macchine: MachineUnit.eureka_billing_customer_code, che viene
 * da id_intestatario_fattura_f15 ed e' aggiornato a ogni gestionale:sync.
 *
 * Di default NON tocca nulla: mostra cosa cambierebbe. Serve --scrivi.
 */
class AllineaPagantiSeStessi extends Command
{
    protected $signature = 'eureka:allinea-paganti-se-stessi
        {--tenant=  : Slug tenant (default: tenant master)}
        {--scrivi   : Applica davvero le modifiche (senza, produce solo un report)}
        {--clienti  : Includi anche i paganti impostati a livello di Customer}
        {--anche-vuoti : Rendi esplicito anche dove il pagante e\' semplicemente vuoto}';

    protected $description = "Associa il cliente a se stesso come pagante dove Eureka dice che paga per se' (matricole, e con --clienti anche le anagrafiche)";

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if (! $tenant) {
            return self::FAILURE;
        }

        $scrivi = (bool) $this->option('scrivi');

        $macchine = $this->macchineDaAzzerare($tenant);
        $clienti = $this->option('clienti') ? $this->clientiDaAzzerare($tenant) : collect();

        if ($macchine->isEmpty() && $clienti->isEmpty()) {
            $this->info('Niente da allineare: nessun pagante in contrasto con Eureka.');

            return self::SUCCESS;
        }

        if ($macchine->isNotEmpty()) {
            $this->newLine();
            $this->line('<options=bold>Matricole — Eureka dice che paga il cliente stesso</>');
            $this->table(
                ['Matricola', 'Installata presso', 'Pagante in CRM ora', 'Diventa'],
                $macchine->map(fn ($m) => [
                    $m->serial_number,
                    \Illuminate\Support\Str::limit($m->currentCustomer?->company_name ?? '—', 30),
                    \Illuminate\Support\Str::limit($m->billingCustomer?->company_name ?? '— (vuoto)', 26),
                    \Illuminate\Support\Str::limit($m->currentCustomer?->company_name ?? '—', 26),
                ])->all()
            );
        }

        if ($clienti->isNotEmpty()) {
            $this->newLine();
            $this->line('<options=bold>Anagrafiche — nessuna macchina con pagante terzo, ma il cliente ne ha uno</>');
            $this->warn('Attenzione: qui la fonte Eureka e\' indiretta. Rivedile a mano prima di scrivere.');
            $this->table(
                ['Cliente', 'Citta', 'Pagante da togliere'],
                $clienti->map(fn ($c) => [
                    \Illuminate\Support\Str::limit($c->company_name ?? '—', 38),
                    $c->city ?? '—',
                    \Illuminate\Support\Str::limit($c->billingCustomer?->company_name ?? '—', 30),
                ])->all()
            );
        }

        $this->newLine();

        if (! $scrivi) {
            $this->info("Report soltanto: {$macchine->count()} matricole e {$clienti->count()} anagrafiche da allineare.");
            $this->line('Per applicare: <options=bold>--scrivi</>');

            return self::SUCCESS;
        }

        foreach ($macchine as $m) {
            $m->forceFill(['billing_customer_id' => $m->current_customer_id])->save();
        }

        foreach ($clienti as $c) {
            $c->forceFill(['billing_customer_id' => $c->id])->save();
        }

        $this->info("Fatto: pagante associato a se stesso su {$macchine->count()} matricole e {$clienti->count()} anagrafiche.");

        return self::SUCCESS;
    }

    /**
     * Macchine con un pagante in CRM che Eureka contraddice, dicendo che
     * l'intestatario della fattura e' lo stesso cliente presso cui la
     * macchina e' installata.
     */
    private function macchineDaAzzerare(Tenant $tenant)
    {
        return MachineUnit::withoutGlobalScopes()
            ->with(['currentCustomer', 'billingCustomer'])
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('current_customer_id')
            ->whereNotNull('eureka_billing_customer_code')
            ->get()
            ->filter(function (MachineUnit $m) {
                $sede = $m->currentCustomer?->gestionale_code;

                if (blank($sede) || (string) $m->eureka_billing_customer_code !== (string) $sede) {
                    return false;
                }

                // Due casi diversi, e di default ne trattiamo uno solo.
                //
                // Pagante DIVERSO da quel che dice Eureka: e' un errore vero,
                // oggi quella macchina viene fatturata a qualcun altro.
                //
                // Pagante VUOTO: non e' un errore — invoiceRecipient() gia'
                // ricade sul cliente stesso — ma resta indistinguibile da
                // "nessuno l'ha ancora verificato". Renderlo esplicito e'
                // utile, non urgente: sta dietro a --anche-vuoti per non
                // toccare un centinaio di righe insieme a otto correzioni.
                if ($m->billing_customer_id === null) {
                    return (bool) $this->option('anche-vuoti');
                }

                return $m->billing_customer_id !== $m->current_customer_id;
            })
            ->values();
    }

    /**
     * Anagrafiche con un pagante impostato a livello di cliente, quando
     * nessuna delle loro macchine risulta pagata da terzi secondo Eureka.
     *
     * Fonte piu' debole di quella per-macchina: qui Eureka non dice
     * esplicitamente "paga per se'", si deduce dall'assenza di un pagante
     * terzo su tutte le sue matricole. Per questo sta dietro a --clienti e
     * viene stampata con un avviso.
     */
    private function clientiDaAzzerare(Tenant $tenant)
    {
        return Customer::withoutGlobalScopes()
            ->with('billingCustomer')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('billing_customer_id')
            ->get()
            ->filter(function (Customer $c) use ($tenant) {
                $macchine = MachineUnit::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at')
                    ->where('current_customer_id', $c->id)
                    ->whereNotNull('eureka_billing_customer_code')
                    ->get();

                // Senza macchine note a Eureka non si puo' dedurre niente:
                // meglio lasciare stare che azzerare al buio.
                if ($macchine->isEmpty() || blank($c->gestionale_code)) {
                    return false;
                }

                // Tutte le sue macchine risultano fatturate a lui stesso.
                return $macchine->every(
                    fn (MachineUnit $m) => (string) $m->eureka_billing_customer_code === (string) $c->gestionale_code
                );
            })
            ->values();
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');

        $tenant = $slug
            ? Tenant::where('slug', $slug)->first()
            : Tenant::orderBy('created_at')->first();

        if (! $tenant) {
            $this->error($slug ? "Tenant \"{$slug}\" non trovato." : 'Nessun tenant trovato.');
        }

        return $tenant;
    }
}
