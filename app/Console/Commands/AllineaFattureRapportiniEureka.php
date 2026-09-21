<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use App\Support\Gestionale\FattureRapportino;
use App\Support\Gestionale\RegistroSync;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Chiede a Eureka su quale fattura e' finita la scheda di ogni rapportino
 * non ancora fatturato, e lo annota (ServiceReport::registraFattureEureka).
 * Da qui nell'elenco rapportini la colonna "Fatturato" e il filtro "non
 * ancora fatturati" (richiesta dell'ufficio, 21/09/2026).
 *
 * Si richiede solo quello che ancora non ha fattura: una volta fatturata, la
 * scheda non cambia. --tutti ricontrolla anche quelle, per il caso raro di
 * una scheda finita piu' tardi su una seconda fattura.
 *
 * "Eureka non ha risposto" e "non ancora fatturata" restano distinti: su una
 * chiamata fallita il rapportino non si tocca, e si riprova la notte dopo.
 */
class AllineaFattureRapportiniEureka extends Command
{
    private const OPERAZIONE = 'fatture-rapportini';

    /** Quante schede per giro di chiamate parallele. */
    private const BLOCCO = 300;

    protected $signature = 'eureka:allinea-fatture-rapportini
                            {--tenant= : slug del tenant, di default quello master}
                            {--tutti : ricontrolla anche i rapportini gia\' fatturati}
                            {--dry-run : chiede a Eureka ma non scrive niente}';

    protected $description = 'Annota su ogni rapportino la fattura Eureka su cui e\' finita la sua scheda';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        if (! $tenant->hasGestionaleEurekaCredentials()) {
            $this->error("Il tenant \"{$tenant->name}\" non ha credenziali Eureka configurate.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $client = new EurekaClient($tenant);

        $daControllare = $this->daControllare($tenant);
        $totale = (clone $daControllare)->count();

        RegistroSync::avvio(self::OPERAZIONE, [
            'tenant' => $tenant->slug, 'rapportini' => $totale, 'tutti' => (bool) $this->option('tutti'), 'dry_run' => $dryRun,
        ]);

        $conti = ['controllati' => 0, 'fatturati_ora' => 0, 'ancora_da_fatturare' => 0, 'senza_risposta' => 0];

        $barra = $this->output->createProgressBar($totale);

        $daControllare->chunkById(self::BLOCCO, function (Collection $rapportini) use ($client, $dryRun, &$conti, $barra) {
            $percorsi = $rapportini->mapWithKeys(fn (ServiceReport $r) => [
                $r->id => '/show/q/sl_fattura?'.http_build_query(['q' => $r->idSchedaEureka()]),
            ])->all();

            $risposte = $client->pooledGetByPath($percorsi);
            $fallite = array_flip(array_map('strval', $client->chiaviPooledFallite()));

            foreach ($rapportini as $rapportino) {
                $barra->advance();

                if (isset($fallite[(string) $rapportino->id])) {
                    $conti['senza_risposta']++;

                    continue;
                }

                $conti['controllati']++;
                $fatture = array_values(array_filter($risposte[$rapportino->id] ?? [], 'is_array'));

                if ($fatture === []) {
                    $conti['ancora_da_fatturare']++;
                } elseif ($rapportino->eureka_fatturato_il === null) {
                    $conti['fatturati_ora']++;

                    RegistroSync::movimento(self::OPERAZIONE, 'fatturato', [
                        'rapportino' => $rapportino->number,
                        'scheda' => $rapportino->idSchedaEureka(),
                        'fattura' => FattureRapportino::etichetta($fatture[0]),
                    ]);
                }

                if (! $dryRun) {
                    $rapportino->registraFattureEureka($fatture);
                }
            }
        }, 'id');

        $barra->finish();
        $this->newLine(2);

        $this->table(['', 'Rapportini'], [
            ['Controllati', $conti['controllati']],
            ['Fatturati da questo giro', $conti['fatturati_ora']],
            ['Senza fattura collegata', $conti['ancora_da_fatturare']],
            ['Eureka non ha risposto (si riprova)', $conti['senza_risposta']],
        ]);

        if ($dryRun) {
            $this->warn('--dry-run: niente e\' stato scritto.');
        }

        RegistroSync::esito(self::OPERAZIONE, $conti);

        if ($conti['senza_risposta'] > 0) {
            RegistroSync::problema(self::OPERAZIONE, 'schede senza risposta', ['quante' => $conti['senza_risposta']]);
        }

        // Fallisce solo se Eureka non ha risposto a niente: qualche buco si
        // recupera la notte dopo, un giro tutto a vuoto va guardato.
        return $conti['controllati'] === 0 && $conti['senza_risposta'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** I rapportini che hanno una scheda su Eureka e, di norma, nessuna fattura ancora. */
    private function daControllare(Tenant $tenant): Builder
    {
        return ServiceReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            ->where(fn (Builder $q) => $q->whereNotNull('eureka_service_report_id')->orWhereNotNull('gestionale_scheda_lavoro_id'))
            ->when(! $this->option('tutti'), fn (Builder $q) => $q->whereNull('eureka_fatturato_il'))
            ->select(['id', 'tenant_id', 'number', 'eureka_service_report_id', 'gestionale_scheda_lavoro_id', 'eureka_fatturato_il']);
    }
}
