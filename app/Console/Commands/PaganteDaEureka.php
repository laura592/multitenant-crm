<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\PaganteEureka;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Riallinea il pagante dei rapportini nel gestionale a chi ha ricevuto la
 * fattura su Eureka (22/09/2026): se il documento e' nel gestionale, chi
 * paga e' quello e non cambia piu'. Vedi PaganteEureka.
 *
 * Usa le fatture gia' nel CRM (eureka_fatture sul rapportino, collegate
 * ogni notte, e l'elenco fatture clienti): nessuna chiamata a Eureka, e
 * li' non si scrive niente. Nel CRM tocca solo il pagante.
 *
 * Di default mostra soltanto; scrive con --esegui, dopo conferma. Da qui
 * in poi lo stesso allineamento lo fa da solo, ogni notte, il collegamento
 * delle fatture (ServiceReport::registraFattureEureka).
 */
class PaganteDaEureka extends Command
{
    protected $signature = 'rapportini:pagante-da-eureka
        {--tenant=  : Slug tenant (default: tenant master)}
        {--tutti    : Anche i rapportini che hanno gia\' un pagante fissato}
        {--esegui   : Scrive le correzioni (senza, mostra soltanto)}';

    protected $description = 'Mostra (e con --esegui scrive) il pagante dei rapportini nel gestionale secondo la fattura Eureka';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $rapportini = ServiceReport::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q
                ->where('source', ServiceReport::SOURCE_EUREKA)
                ->orWhere('gestionale_sync_status', 'sent')
                ->orWhereNotNull('eureka_service_report_id'))
            ->when(! $this->option('tutti'), fn ($q) => $q->whereNull('billing_customer_id'))
            ->with(['customer', 'billingCustomer', 'machineUnit.billingCustomer'])
            ->get();

        $this->info("Rapportini nel gestionale da controllare: {$rapportini->count()}");

        $senzaFattura = 0;
        $ambigue = collect();
        $correzioni = collect();

        foreach ($rapportini as $r) {
            $fattura = PaganteEureka::esitoFatture($r->eureka_fatture, $r->tenant_id);

            if ($fattura['esito'] === 'ambigua') {
                $ambigue->push($r);

                continue;
            }

            $da = $fattura['customer_id'] ? 'fattura' : 'scheda (destinazione)';
            $pagante = $fattura['customer_id']
                ?? ($r->eureka_destinazione_code ? $r->eurekaDestinazionePayer()?->id : null);

            if ($pagante === null) {
                $senzaFattura++;

                continue;
            }

            if ($pagante !== $r->billing_customer_id) {
                $correzioni->push([$r, $pagante, $da]);
            }
        }

        $this->info("Senza fattura (o fattura non ancora nel CRM): {$senzaFattura} - restano come sono, si allineano da soli quando arriva la fattura.");

        if ($ambigue->isNotEmpty()) {
            $this->warn("Fattura ambigua (stesso numero in due serie, clienti diversi): {$ambigue->count()} - restano come sono, da controllare a mano.");
            $this->table(
                ['Rapportino', 'N. gestionale', 'Data', 'Cliente', 'Fattura'],
                $ambigue->map(fn (ServiceReport $r) => [
                    $r->number,
                    $r->gestionale_number ?? '—',
                    $r->intervention_date?->format('d/m/Y'),
                    $r->customer?->company_name ?? '—',
                    $r->etichettaFatturaEureka() ?? '—',
                ])->all(),
            );
        }

        if ($correzioni->isEmpty()) {
            $this->info('Tutti i paganti sono gia\' allineati alle fatture.');

            return self::SUCCESS;
        }

        $nomi = Customer::withoutGlobalScopes()->whereIn('id', $correzioni->pluck(1)->unique())->pluck('company_name', 'id');

        // Il pagante che il CRM mostra OGGI (calcolato da macchina/cliente se
        // non fissato): e' il confronto che serve per capire cosa cambia.
        $oggi = fn (ServiceReport $r) => rescue(fn () => $r->invoiceRecipient(), null, false);
        [$cambiano, $soloFissati] = $correzioni->partition(fn ($c) => $oggi($c[0])?->id !== $c[1]);

        $this->info("Il pagante resta lo stesso e viene solo fissato: {$soloFissati->count()} rapportini.");
        $this->info("Il pagante CAMBIA: {$cambiano->count()} rapportini.");

        if ($cambiano->isNotEmpty()) {
            $this->table(
                ['Rapportino', 'N. gestionale', 'Data', 'Cliente', 'Pagante oggi nel CRM', 'Pagante secondo Eureka', 'Da', 'Fattura'],
                $cambiano->map(fn ($c) => [
                    $c[0]->number,
                    $c[0]->gestionale_number ?? '—',
                    $c[0]->intervention_date?->format('d/m/Y'),
                    $c[0]->customer?->company_name ?? '—',
                    $oggi($c[0])?->company_name ?? '—',
                    $nomi[$c[1]] ?? '—',
                    $c[2],
                    $c[0]->etichettaFatturaEureka() ?? '—',
                ])->all(),
            );
        }

        if (! $this->option('esegui')) {
            $this->warn('Solo anteprima: niente e\' stato scritto. Rilancia con --esegui per applicare.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Scrivere come pagante l'intestatario della fattura su {$correzioni->count()} rapportini?")) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($correzioni) {
            foreach ($correzioni as [$rapportino, $pagante]) {
                // Senza eventi: solo il pagante, nessun ricalcolo di piani,
                // lavaggi o altro legato al salvataggio del rapportino.
                $rapportino->forceFill(['billing_customer_id' => $pagante])->saveQuietly();
            }
        });

        $this->info("Fatto: {$correzioni->count()} rapportini allineati alla fattura.");

        return self::SUCCESS;
    }
}
