<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Toglie dai CLIENTI il pagante dedotto dalle schede lavoro.
 *
 * L'anagrafica di Eureka non ha un campo "chi paga": ha nove campi, e sono
 * tutti dati anagrafici (ragione sociale, piva, citta', contatti). Il pagante
 * esiste solo in due posti — sulla singola scheda (destinazione) e sulla
 * singola macchina (id_intestatario_fattura_f15 negli installati) — e solo il
 * secondo e' un dato di anagrafica.
 *
 * eureka:apply-billing-destinazione aveva pero' promosso quel dato a regola
 * del cliente: 51 clienti su 199 si reggevano su UNA sola scheda. Su "Bar
 * Nostro di Marangotto Livio" bastava un intervento del 12/02/2023 fatturato
 * a Illy perche' il CRM dicesse che per quel cliente paga Illy — mentre un
 * altro intervento dello stesso giorno, e uno del 2025, non dicevano niente.
 *
 * Le macchine NON si toccano: li' il pagante ha una fonte vera, ed e' anche
 * piu' giusto nel merito, perche' il torrefattore paga per la macchina che ha
 * dato in comodato, non per tutto quello che si fa da quel cliente.
 * invoiceRecipient() guarda comunque prima la macchina.
 */
class PuliscePaganteClienti extends Command
{
    protected $signature = 'clienti:pulisci-pagante
        {--tenant=       : Slug tenant (default: tenant master)}
        {--dry-run       : Mostra il diff senza scrivere nulla}
        {--force         : Non chiedere conferma}';

    protected $description = 'Toglie dai clienti il pagante dedotto dalle schede (le macchine restano intatte)';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');

        $clienti = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('billing_customer_id')
            ->with('billingCustomer')
            ->orderBy('company_name')
            ->get();

        if ($clienti->isEmpty()) {
            $this->info('Nessun cliente ha un pagante impostato.');

            return self::SUCCESS;
        }

        // Su quante schede si regge ciascuno: e' il numero che dice se il
        // pagante era una regola o una coincidenza.
        $righe = $clienti->map(function (Customer $c) {
            $schede = ServiceReport::query()
                ->where('customer_id', $c->id)
                ->whereNull('deleted_at')
                ->where('eureka_destinazione_code', $c->billingCustomer?->gestionale_code)
                ->count();

            return [
                'cliente' => mb_substr((string) $c->company_name, 0, 34),
                'pagante' => mb_substr((string) $c->billingCustomer?->company_name, 0, 26),
                'schede' => $schede,
            ];
        })->sortBy('schede')->values();

        $this->line("Clienti con un pagante impostato: {$clienti->count()}");
        $this->table(
            ['Cliente', 'Pagante', 'Schede che lo dicono'],
            $righe->take(12)->map(fn (array $r) => array_values($r))->all(),
        );

        $deboli = $righe->where('schede', '<=', 1)->count();
        $this->warn("Di questi, {$deboli} si reggono su una sola scheda (o su nessuna).");
        $this->line('Le macchine non vengono toccate: li\' il pagante viene dagli installati di Eureka.');

        if ($dryRun) {
            $this->comment('Prova a vuoto: non e\' stato scritto nulla.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Tolgo il pagante a {$clienti->count()} clienti?", false)) {
            $this->comment('Annullato.');

            return self::SUCCESS;
        }

        $puliti = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('billing_customer_id')
            ->update(['billing_customer_id' => null]);

        $this->info("Clienti ripuliti: {$puliti}.");

        return self::SUCCESS;
    }

    private function resolveTenant(): Tenant
    {
        $slug = trim((string) $this->option('tenant'));

        return $slug !== ''
            ? Tenant::query()->where('slug', $slug)->firstOrFail()
            : Tenant::query()->where('is_master', true)->firstOrFail();
    }
}
