<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
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
 *
 * E proprio le macchine dicono quali valori sul cliente salvare: dove
 * concordano, il dato non e' inventato e resta (80 su 199 al 04/09/2026).
 * Si toglie il resto — chi non ha macchine, chi le ha senza pagante, e i 12
 * dove le macchine indicano qualcun ALTRO: li' il dato sul cliente non e'
 * debole, e' sbagliato. Due esempi che lo mostrano da soli: "Biennale
 * Giardini Chiosco" e "Biennale Giardini Terrazza" hanno i paganti
 * incrociati fra loro. Con --tutti si toglie anche quello confermato.
 */
class PuliscePaganteClienti extends Command
{
    protected $signature = 'clienti:pulisci-pagante
        {--tenant=       : Slug tenant (default: tenant master)}
        {--tutti         : Toglie il pagante anche dove le macchine lo confermano}
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

        // Il confronto che conta non e' quante schede lo dicono, ma se le
        // MACCHINE di quel cliente dicono lo stesso: li' il pagante ha una
        // fonte vera (id_intestatario_fattura_f15 negli installati). Dove
        // concordano, il dato sul cliente non e' inventato e si tiene.
        $gruppi = ['confermato' => [], 'non confermato' => []];

        foreach ($clienti as $cliente) {
            $macchine = MachineUnit::query()
                ->where('current_customer_id', $cliente->id)
                ->whereNull('deleted_at')
                ->whereNotNull('billing_customer_id')
                ->pluck('billing_customer_id');

            $concordi = $macchine->contains($cliente->billing_customer_id);
            $discordi = $macchine->reject(fn ($id) => $id === $cliente->billing_customer_id);

            $motivo = match (true) {
                $macchine->isEmpty() => 'nessuna macchina lo conferma',
                $concordi && $discordi->isEmpty() => 'confermato dalle macchine',
                $concordi => 'confermato in parte',
                default => 'LE MACCHINE DICONO ALTRO',
            };

            $gruppi[$concordi && $discordi->isEmpty() ? 'confermato' : 'non confermato'][] = [
                'cliente' => mb_substr((string) $cliente->company_name, 0, 32),
                'pagante' => mb_substr((string) $cliente->billingCustomer?->company_name, 0, 24),
                'motivo' => $motivo,
                'id' => $cliente->id,
            ];
        }

        $daTogliere = collect($this->option('tutti')
            ? array_merge($gruppi['confermato'], $gruppi['non confermato'])
            : $gruppi['non confermato']);

        $this->line('Clienti con un pagante impostato: '.$clienti->count());
        $this->line('  confermato dalle macchine: '.count($gruppi['confermato']).($this->option('tutti') ? ' (verra\' tolto anche questo)' : ' — si tiene'));
        $this->line('  non confermato:            '.count($gruppi['non confermato']));
        $this->newLine();

        if ($daTogliere->isEmpty()) {
            $this->info('Niente da togliere.');

            return self::SUCCESS;
        }

        $this->table(
            ['Cliente', 'Pagante', 'Perche\''],
            $daTogliere->take(12)->map(fn (array $r) => [$r['cliente'], $r['pagante'], $r['motivo']])->all(),
        );

        $contraddetti = $daTogliere->where('motivo', 'LE MACCHINE DICONO ALTRO')->count();

        if ($contraddetti > 0) {
            $this->warn("{$contraddetti} hanno macchine che indicano un pagante DIVERSO: li' il dato sul cliente e' sbagliato, non solo debole.");
        }

        $this->line('Le macchine non vengono toccate in nessun caso.');

        if ($dryRun) {
            $this->comment('Prova a vuoto: non e\' stato scritto nulla.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Tolgo il pagante a '.$daTogliere->count().' clienti?', false)) {
            $this->comment('Annullato.');

            return self::SUCCESS;
        }

        $puliti = Customer::query()
            ->whereIn('id', $daTogliere->pluck('id'))
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
