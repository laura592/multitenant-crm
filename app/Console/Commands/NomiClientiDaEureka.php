<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\EurekaClient;
use Illuminate\Console\Command;

/**
 * Da' un nome ai clienti rimasti "Cliente Eureka 2933" (23/09/2026).
 *
 * Nascono cosi' quando l'import dei rapportini (2026-08-06) trova una
 * scheda intestata a un codice che nel CRM non c'e' ancora e la risposta
 * di elenco non porta la ragione sociale: il cliente si crea lo stesso,
 * col codice al posto del nome. Da li' non si sistema piu' da solo,
 * perche' il sync cerca l'anagrafica PER NOME e "Cliente Eureka 2933" su
 * Eureka non esiste (per codice l'API non si puo' interrogare).
 *
 * Il nome vero sta nel DETTAGLIO della scheda (intestatario.rag_sociale):
 * una lettura per cliente, e solo per questi. Nessuna scrittura verso
 * Eureka. Una volta rinominati, il sync li ritrova e completa da solo
 * partita IVA, indirizzo e contatti.
 */
class NomiClientiDaEureka extends Command
{
    protected $signature = 'clienti:nomi-da-eureka
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra i nomi trovati senza scrivere}';

    protected $description = 'Rimette il nome vero ai clienti rimasti "Cliente Eureka <codice>", leggendolo dalle schede';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $segnaposto = Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('gestionale_code')
            ->where('company_name', 'like', 'Cliente Eureka %')
            ->get();

        if ($segnaposto->isEmpty()) {
            $this->info('Nessun cliente senza nome.');

            return self::SUCCESS;
        }

        $this->info($segnaposto->count().' clienti senza nome: leggo le schede su Eureka.');

        $client = app(EurekaClient::class);
        $trovati = [];
        $senzaScheda = [];

        foreach ($segnaposto as $cliente) {
            $scheda = ServiceReport::query()
                ->withoutGlobalScopes()
                ->where('customer_id', $cliente->id)
                ->whereNotNull('eureka_service_report_id')
                ->latest('intervention_date')
                ->first();

            $dettaglio = $scheda?->idSchedaEureka() ? $client->getServiceReport((int) $scheda->idSchedaEureka()) : [];
            $intestatario = is_array($dettaglio['intestatario'] ?? null) ? $dettaglio['intestatario'] : [];
            $nome = trim((string) ($intestatario['rag_sociale'] ?? ''));

            // La scheda deve parlare proprio di lui: un id diverso vorrebbe
            // dire che il rapportino e' attaccato al cliente sbagliato, e
            // rinominare peggiorerebbe le cose.
            if ($nome === '' || (int) ($intestatario['id_eureka'] ?? 0) !== (int) $cliente->gestionale_code) {
                $senzaScheda[] = $cliente;

                continue;
            }

            $trovati[] = ['cliente' => $cliente, 'nome' => $nome];
        }

        if ($trovati !== []) {
            $this->table(['Codice', 'Adesso', 'Diventa'], collect($trovati)->map(fn (array $r) => [
                $r['cliente']->gestionale_code,
                $r['cliente']->company_name,
                $r['nome'],
            ])->all());
        }

        if ($senzaScheda !== []) {
            $this->warn('Senza nome leggibile (nessuna scheda collegata, o intestata a un altro codice): '
                .collect($senzaScheda)->pluck('gestionale_code')->implode(', '));
        }

        if ($trovati === []) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: '.count($trovati).' nomi trovati, niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Scrivo i '.count($trovati).' nomi?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($trovati as $r) {
            $r['cliente']->update(['company_name' => $r['nome']]);
        }

        $this->info('Fatto: '.count($trovati).' clienti con il nome vero.');
        $this->line('Il prossimo gestionale:sync li ritrova per nome e completa partita IVA, indirizzo e contatti.');

        return self::SUCCESS;
    }
}
