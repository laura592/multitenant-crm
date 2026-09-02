<?php

namespace App\Console\Commands;

use App\Models\EurekaCashflowMese;
use App\Models\EurekaCashflowVoce;
use App\Models\EurekaFatturatoMese;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ricarica gli aggregati contabili di Eureka: fatturato per mese e cash flow
 * prospettico.
 *
 * Sono gli unici due numeri del modulo contabile che NON ricostruiamo da
 * soli. Il fatturato perche' il netto di Eureka pesa le causali col piano
 * dei conti e conta per data di registrazione, quindi rifarlo dalla nostra
 * copia delle fatture darebbe un numero diverso da quello che l'ufficio
 * vede sul gestionale. Il cash flow perche' incrocia scadenziario e
 * documenti B10 aperti, roba che in locale non abbiamo affatto.
 *
 * Poche chiamate: 2 per il fatturato (clienti e fornitori), 1 per il cash
 * flow, piu' una per ciascun mese di cui si vuole il dettaglio.
 */
class ImportEurekaKpiContabili extends Command
{
    protected $signature = 'eureka:import-kpi-contabili {--tenant=} {--anni=3}';

    protected $description = 'Ricarica da Eureka il fatturato mensile e il cash flow prospettico';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        if (! $tenant->hasGestionaleEurekaCredentials()) {
            $this->error("Il tenant \"{$tenant->name}\" non ha credenziali Eureka configurate.");

            return self::FAILURE;
        }

        $client = new EurekaClient($tenant);

        $fallito = $this->importaFatturato($client, $tenant)
            | $this->importaCashflow($client, $tenant);

        foreach ($client->failedEndpoints() as $problema) {
            $this->warn("{$problema['endpoint']}: {$problema['failures']}/{$problema['attempts']} fallite ({$problema['statuses']}).");
        }

        return $fallito ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Fatturato clienti e fornitori, dal 1° gennaio di N anni fa a oggi.
     *
     * Come per gli altri import, ogni lato si sostituisce da solo: se
     * risponde solo quello clienti, i mesi dei fornitori restano quelli di
     * ieri invece di sparire.
     */
    private function importaFatturato(EurekaClient $client, Tenant $tenant): bool
    {
        $dal = now()->subYears(max(0, (int) $this->option('anni')))->startOfYear()->toDateString();
        $al = now()->toDateString();

        $fallito = false;

        foreach ([EurekaFatturatoMese::TIPO_CLIENTE, EurekaFatturatoMese::TIPO_FORNITORE] as $tipo) {
            $risposta = $client->fatturato($tipo === EurekaFatturatoMese::TIPO_FORNITORE, $dal, $al);

            if ($risposta === null) {
                $this->error("Fatturato {$tipo}: Eureka non ha risposto, i mesi in archivio restano invariati.");
                $fallito = true;

                continue;
            }

            $adesso = now();
            $righe = [];

            foreach ($risposta['mesi'] ?? [] as $mese) {
                $righe[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'tipo' => $tipo,
                    'anno' => (int) ($mese['anno'] ?? 0),
                    'mese' => (int) ($mese['mese'] ?? 0),
                    'dare' => round((float) ($mese['dare'] ?? 0), 2),
                    'avere' => round((float) ($mese['avere'] ?? 0), 2),
                    'netto' => round((float) ($mese['netto'] ?? 0), 2),
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ];
            }

            DB::transaction(function () use ($tenant, $tipo, $righe) {
                EurekaFatturatoMese::where('tenant_id', $tenant->id)->where('tipo', $tipo)->delete();
                EurekaFatturatoMese::insert($righe);
            });

            $this->info(sprintf(
                'Fatturato %s: %d mesi, netto %s su %d documenti.',
                $tipo,
                count($righe),
                number_format((float) ($risposta['netto'] ?? 0), 2, ',', '.'),
                (int) ($risposta['nr_doc'] ?? 0),
            ));
        }

        return $fallito;
    }

    /**
     * Cash flow prospettico e, per ogni mese che ha un movimento, le voci
     * che lo compongono.
     *
     * Il dettaglio si chiede SOLO per i mesi che hanno qualcosa dentro: su
     * un orizzonte di due anni sarebbero 24 chiamate, di cui la maggior
     * parte per mesi vuoti.
     */
    private function importaCashflow(EurekaClient $client, Tenant $tenant): bool
    {
        $dal = now()->startOfYear()->toDateString();
        $al = now()->addYear()->endOfYear()->toDateString();

        $risposta = $client->cashflow($dal, $al);

        if ($risposta === null) {
            $this->error('Cash flow: Eureka non ha risposto, la previsione in archivio resta invariata.');

            return true;
        }

        $adesso = now();
        $mesi = [];

        foreach ($risposta['mesi'] ?? [] as $m) {
            $mesi[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'anno' => (int) ($m['anno'] ?? 0),
                'mese' => (int) ($m['mese'] ?? 0),
                'entrate' => round((float) ($m['entrate'] ?? 0), 2),
                'uscite' => round((float) ($m['uscite'] ?? 0), 2),
                'entrate_ftc' => round((float) ($m['entrate_ftc'] ?? 0), 2),
                'entrate_oc' => round((float) ($m['entrate_oc'] ?? 0), 2),
                'entrate_bc' => round((float) ($m['entrate_bc'] ?? 0), 2),
                'uscite_ftf' => round((float) ($m['uscite_ftf'] ?? 0), 2),
                'uscite_of' => round((float) ($m['uscite_of'] ?? 0), 2),
                'uscite_bf' => round((float) ($m['uscite_bf'] ?? 0), 2),
                'saldo_mese' => round((float) ($m['saldo_mese'] ?? 0), 2),
                'saldo_progressivo' => round((float) ($m['saldo_progressivo'] ?? 0), 2),
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ];
        }

        // Il dettaglio si scarica PRIMA di toccare l'archivio: se a meta'
        // strada Eureka smette di rispondere, si conserva quello di ieri
        // invece di restare con una previsione senza spiegazioni sotto.
        $voci = [];
        $mesiSenzaDettaglio = [];

        foreach ($mesi as $m) {
            if ((float) $m['entrate'] === 0.0 && (float) $m['uscite'] === 0.0) {
                continue;
            }

            $dettaglio = $client->cashflowDettaglio($m['anno'], $m['mese']);

            if ($dettaglio === null) {
                $mesiSenzaDettaglio[] = "{$m['mese']}/{$m['anno']}";

                continue;
            }

            foreach ($dettaglio as $v) {
                $voci[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'anno' => $m['anno'],
                    'mese' => $m['mese'],
                    'data_documento' => $this->data($v['data_documento'] ?? null),
                    'data_scadenza' => $this->data($v['data_scadenza'] ?? null),
                    'numero' => isset($v['numero']) ? trim((string) $v['numero']) : null,
                    'descrizione' => $v['descrizione'] ?? null,
                    'tipo' => $v['tipo'] ?? null,
                    'importo_totale' => round((float) ($v['importo_totale'] ?? 0), 2),
                    'importo' => round((float) ($v['importo'] ?? 0), 2),
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ];
            }
        }

        DB::transaction(function () use ($tenant, $mesi, $voci) {
            EurekaCashflowMese::where('tenant_id', $tenant->id)->delete();
            EurekaCashflowVoce::where('tenant_id', $tenant->id)->delete();

            foreach (array_chunk($mesi, 500) as $blocco) {
                EurekaCashflowMese::insert($blocco);
            }

            foreach (array_chunk($voci, 500) as $blocco) {
                EurekaCashflowVoce::insert($blocco);
            }
        });

        $this->info(sprintf(
            'Cash flow: %d mesi, %d voci. Entrate %s, uscite %s, saldo %s.',
            count($mesi),
            count($voci),
            number_format((float) ($risposta['totale_entrate'] ?? 0), 2, ',', '.'),
            number_format((float) ($risposta['totale_uscite'] ?? 0), 2, ',', '.'),
            number_format((float) ($risposta['saldo_netto'] ?? 0), 2, ',', '.'),
        ));

        if ($mesiSenzaDettaglio !== []) {
            $this->warn('  dettaglio non scaricato per: '.implode(', ', $mesiSenzaDettaglio));
        }

        return false;
    }

    private function data(mixed $valore): ?string
    {
        if (blank($valore)) {
            return null;
        }

        try {
            // Il dettaglio cash flow torna gg/mm/aaaa, gli altri endpoint
            // ISO: Carbon da solo leggerebbe 10/09/2026 come 9 ottobre.
            return preg_match('#^\d{2}/\d{2}/\d{4}$#', (string) $valore)
                ? Carbon::createFromFormat('d/m/Y', (string) $valore)->toDateString()
                : Carbon::parse((string) $valore)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
