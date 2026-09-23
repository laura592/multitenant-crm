<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scrive sulla macchina chi paga, invece di lasciarlo ereditare
 * dall'anagrafica del cliente (23/09/2026).
 *
 * Chi paga e' della macchina, non del cliente: Bar Miki ha in anagrafica
 * "per lui paga Dersut", ma l'impianto spina e la casetta dell'acqua sono
 * suoi, e i rapportini finivano a Dersut. Qui la scelta diventa esplicita:
 *
 * - impianti acqua, impianti spina e casette: paga il cliente stesso;
 * - tutto il resto: il pagante dell'anagrafica, scritto a chiare lettere.
 *
 * Non tocca le macchine che hanno gia' un pagante proprio, ne' quelle per
 * cui Eureka ne indica uno (li' decide Eureka, ogni notte alle 03:15).
 */
class PaganteEsplicitoMacchine extends Command
{
    protected $signature = 'macchine:pagante-esplicito
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe, senza scrivere}';

    protected $description = 'Scrive sulla macchina chi paga, invece di ereditarlo dall\'anagrafica del cliente';

    /** Impianti e casette: sono del locale, non del torrefattore. */
    private const DEL_CLIENTE_SE_NEL_NOME = ['impianto acqua', 'impianto spina', 'casetta'];

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $macchine = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNull('billing_customer_id')
            ->whereNull('eureka_billing_customer_code')
            ->whereNotNull('current_customer_id')
            ->with(['currentCustomer' => fn ($q) => $q->withoutGlobalScopes()->with('billingCustomer')])
            ->get()
            ->filter(fn (MachineUnit $m) => $m->currentCustomer?->billing_customer_id !== null);

        if ($macchine->isEmpty()) {
            $this->info('Nessuna macchina eredita il pagante dall\'anagrafica.');

            return self::SUCCESS;
        }

        $scelte = $macchine->map(function (MachineUnit $m) {
            $cliente = $m->currentCustomer;
            $suo = self::eDelCliente($m);

            return [
                'macchina' => $m,
                'pagante' => $suo ? $cliente : $cliente->billingCustomer,
                'perche' => $suo ? 'impianto del locale' : 'come oggi, ma scritto sulla macchina',
            ];
        })->values();

        $this->table(['Cliente', 'Matricola', 'Macchina', 'Pagherà', 'Perché'], $scelte->map(fn (array $s) => [
            DisplayName::titleCase($s['macchina']->currentCustomer->company_name),
            $s['macchina']->serial_number,
            Str::limit((string) $s['macchina']->display_name, 30),
            DisplayName::titleCase($s['pagante']->company_name),
            $s['perche'],
        ])->all());

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: '.$scelte->count().' macchine da scrivere, niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Scrivo il pagante su '.$scelte->count().' macchine?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($scelte as $s) {
            $s['macchina']->update(['billing_customer_id' => $s['pagante']->id]);
        }

        $this->info('Fatto: '.$scelte->count().' macchine con il pagante scritto.');
        $this->line('I rapportini gia\' chiusi tengono il pagante di allora: si correggono uno per uno.');

        return self::SUCCESS;
    }

    /** L'impianto e' del locale: lo dice il tipo, o il nome quando il tipo manca. */
    private static function eDelCliente(MachineUnit $macchina): bool
    {
        if (in_array($macchina->type, [MachineUnit::TYPE_IMPIANTO_ACQUA, MachineUnit::TYPE_COLONNA_SPINA], true)) {
            return true;
        }

        $nome = Str::lower((string) $macchina->model_name);

        return Str::contains($nome, self::DEL_CLIENTE_SE_NEL_NOME);
    }
}
