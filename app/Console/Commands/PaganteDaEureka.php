<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\ControlloPaganteFattura;
use App\Support\Gestionale\PaganteEureka;
use Illuminate\Console\Command;

/**
 * Copia nel CRM il pagante delle schede Eureka dei rapportini gia' nel
 * gestionale (22/09/2026): la destinazione della scheda, o l'intestatario se
 * vuota. Il CRM non lo decide mai da se' (PaganteEureka).
 *
 * Serve per lo storico: l'import notturno rilegge solo le schede degli
 * ultimi 7 giorni. Legge da Eureka (una chiamata per scheda, in gruppi) e li'
 * non scrive niente; nel CRM tocca solo pagante e destinazione.
 *
 * Di default mostra soltanto; scrive con --esegui, dopo conferma. Dopo,
 * ricalcola le schede da correggere su Eureka (fattura intestata a un altro).
 */
class PaganteDaEureka extends Command
{
    protected $signature = 'rapportini:pagante-da-eureka
        {--tenant=      : Slug tenant (default: tenant master)}
        {--rapportini=  : Solo questi rapportini, separati da virgola (RT-... o SL-...)}
        {--esegui       : Scrive (senza, mostra soltanto)}';

    protected $description = 'Copia nel CRM il pagante delle schede Eureka dei rapportini nel gestionale (mostra; con --esegui scrive)';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $solo = array_filter(array_map('trim', explode(',', (string) $this->option('rapportini'))));

        $rapportini = ControlloPaganteFattura::rapportiniNelGestionale($tenant->id)
            ->when($solo !== [], fn ($q) => $q->where(fn ($q) => $q->whereIn('number', $solo)->orWhereIn('gestionale_number', $solo)))
            ->with(['customer.billingCustomer', 'billingCustomer', 'machineUnit.billingCustomer'])
            ->get()
            ->filter(fn (ServiceReport $r) => $r->idSchedaEureka() !== null)
            ->values();

        $this->info("Schede da rileggere su Eureka: {$rapportini->count()}");

        // A blocchi, con la barra: sono migliaia di schede, una chiamata
        // ciascuna, e in silenzio sembrava fermo (~40 minuti per tutte).
        $esito = ['letti' => 0, 'cambiati' => [], 'non_trovati' => [], 'non_letti' => 0, 'da_scrivere' => []];
        $barra = $this->output->createProgressBar($rapportini->count());
        $barra->start();

        foreach ($rapportini->chunk(100) as $blocco) {
            $parziale = PaganteEureka::rileggiSchede($blocco->values(), scrivi: false);
            $esito['letti'] += $parziale['letti'];
            $esito['non_letti'] += $parziale['non_letti'];
            foreach (['cambiati', 'non_trovati', 'da_scrivere'] as $k) {
                $esito[$k] = [...$esito[$k], ...$parziale[$k]];
            }
            $barra->advance($blocco->count());
        }

        $barra->finish();
        $this->output->writeln(["", ""]);

        if ($esito['non_letti'] > 0) {
            $this->warn("Non lette (Eureka non ha risposto): {$esito['non_letti']} - restano come sono, rilancia piu' tardi.");
        }

        if ($esito['non_trovati'] !== []) {
            $this->warn('Pagante indicato sulla scheda ma non presente nel CRM ('.count($esito['non_trovati']).'): resta com\'e\'. Va creato o collegato il cliente.');
            $this->table(['Rapportino', 'N. gestionale', 'Cliente', 'Pagante sulla scheda'], array_map(fn ($x) => [
                $x[0]->number, $x[0]->gestionale_number ?? '—', $x[0]->customer?->company_name ?? '—', $x[1] ?? '—',
            ], $esito['non_trovati']));
        }

        $nomi = Customer::withoutGlobalScopes()->whereIn('id', collect($esito['cambiati'])->flatMap(fn ($c) => [$c[1], $c[2]])->filter()->unique())->pluck('company_name', 'id');

        $this->info('Il pagante resta lo stesso: '.($esito['letti'] - count($esito['cambiati'])).' rapportini.');
        $this->info('Il pagante CAMBIA: '.count($esito['cambiati']).' rapportini.');

        if ($esito['cambiati'] !== []) {
            $this->table(['Rapportino', 'N. gestionale', 'Data', 'Cliente', 'Pagante oggi nel CRM', 'Pagante sulla scheda Eureka'], array_map(fn ($c) => [
                $c[0]->number, $c[0]->gestionale_number ?? '—', $c[0]->intervention_date?->format('d/m/Y'),
                $c[0]->customer?->company_name ?? '—', $nomi[$c[1]] ?? '—', $nomi[$c[2]] ?? '—',
            ], $esito['cambiati']));
        }

        if (! $this->option('esegui')) {
            $this->warn('Solo anteprima: niente e\' stato scritto. Rilancia con --esegui per copiare il pagante delle schede.');

            return self::SUCCESS;
        }

        if ($esito['da_scrivere'] === [] || ! $this->confirm('Copiare nel CRM il pagante delle schede su '.count($esito['da_scrivere']).' rapportini?')) {
            return self::SUCCESS;
        }

        PaganteEureka::scrivi($esito['da_scrivere']);
        $this->info('Fatto: '.count($esito['da_scrivere']).' rapportini allineati alle schede.');

        $daCorreggere = ControlloPaganteFattura::segnala($tenant);
        $this->info("Schede da correggere su Eureka (fattura intestata a un altro): {$daCorreggere} - le trovi in Verifica sync gestionale.");

        return self::SUCCESS;
    }
}
