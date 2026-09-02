<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\EurekaPartitaAperta;
use App\Models\EurekaSaldoAnagrafica;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ricarica la fotografia delle partite aperte da Eureka
 * (esposizione clienti/fornitori e scaduto).
 *
 * Due passaggi: /contabilita/saldi dice QUALI anagrafiche hanno qualcosa di
 * aperto (~87 clienti, ~56 fornitori su oltre 2000 anagrafiche), poi
 * /contabilita/partitaaperta/{id} porta il dettaglio con le scadenze. Senza
 * il primo passaggio servirebbero migliaia di chiamate per scoprire che quasi
 * tutte non hanno nulla.
 */
class ImportEurekaPartiteAperte extends Command
{
    protected $signature = 'eureka:import-partite-aperte {--tenant=}';

    protected $description = 'Ricarica da Eureka le partite aperte di clienti e fornitori';

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

        // Le due liste si raccolgono e si sostituiscono SEPARATAMENTE.
        //
        // Prima erano un unico array e un'unica DELETE: bastava che
        // /contabilita/saldi andasse in errore mentre
        // /contabilita/saldi/fornitori rispondeva perche' l'array
        // complessivo risultasse pieno, la guardia non scattasse, e la
        // DELETE portasse via TUTTE le partite clienti per riscrivere solo
        // i fornitori. Lo scaduto clienti si sarebbe svuotato di colpo
        // senza un solo messaggio d'errore — e i 500 a raffica di Eureka
        // sono documentati, non ipotetici.
        $lati = [];
        $falliti = [];

        foreach ([EurekaPartitaAperta::TIPO_CLIENTE, EurekaPartitaAperta::TIPO_FORNITORE] as $tipo) {
            $lato = $this->raccogli($client, $tenant, $tipo);

            if ($lato === null) {
                $falliti[] = $tipo;

                continue;
            }

            $lati[$tipo] = $lato;
        }

        foreach ($falliti as $tipo) {
            $this->error("Eureka non ha risposto per le partite {$tipo}: quelle gia' in archivio restano invariate.");
        }

        if ($lati === []) {
            return self::FAILURE;
        }

        // Sostituzione in blocco dentro una transazione: le partite aperte
        // sono uno stato, non un registro. Una chiusa su Eureka deve sparire
        // anche qui, e un aggiornamento riga per riga lascerebbe indietro
        // proprio quelle (non tornano piu' nella risposta, quindi nessun
        // ciclo sui dati nuovi le incontrerebbe mai).
        //
        // Si risparmiano pero' le anagrafiche il cui dettaglio e' andato in
        // errore: cancellarle e non riscriverle le farebbe sparire dallo
        // scaduto, cioe' quel cliente non lo chiamerebbe piu' nessuno.
        // Meglio la loro riga di ieri.
        DB::transaction(function () use ($tenant, $lati) {
            foreach ($lati as $tipo => $lato) {
                $query = EurekaPartitaAperta::where('tenant_id', $tenant->id)->where('tipo', $tipo);

                if ($lato['codici_falliti'] !== []) {
                    $query->whereNotIn('gestionale_code', $lato['codici_falliti']);
                }

                $query->delete();

                foreach (array_chunk($lato['righe'], 500) as $blocco) {
                    EurekaPartitaAperta::insert($blocco);
                }

                // Il saldo che Eureka dichiara per ciascuna anagrafica, che
                // finora buttavamo via dopo averlo usato solo per sapere a
                // chi chiedere il dettaglio. Costa zero chiamate in piu' ed
                // e' l'unico modo di accorgersi che le nostre partite
                // raccontano una storia diversa dal gestionale.
                EurekaSaldoAnagrafica::where('tenant_id', $tenant->id)->where('tipo', $tipo)->delete();

                foreach (array_chunk($lato['saldi'], 500) as $blocco) {
                    EurekaSaldoAnagrafica::insert($blocco);
                }
            }
        });

        foreach ($lati as $tipo => $lato) {
            $this->info(sprintf(
                'Partite aperte %s: %d righe (%s).',
                $tipo,
                count($lato['righe']),
                number_format((float) collect($lato['righe'])->sum('saldo'), 2, ',', '.'),
            ));

            if ($lato['codici_falliti'] !== []) {
                $this->warn(sprintf(
                    '  dettaglio non scaricato per %d anagrafiche %s (%s): restano i dati precedenti.',
                    count($lato['codici_falliti']),
                    $tipo,
                    implode(', ', array_slice($lato['codici_falliti'], 0, 10)),
                ));
            }
        }

        foreach ($client->failedEndpoints() as $problema) {
            $this->warn("{$problema['endpoint']}: {$problema['failures']}/{$problema['attempts']} fallite ({$problema['statuses']}).");
        }

        return $falliti === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * NULL se l'elenco dei saldi non e' arrivato: senza quello non si sa
     * nemmeno chi ha una partita aperta, e cancellare sarebbe cieco. Un
     * elenco vuoto invece e' una risposta valida ("nessuna partita") e
     * autorizza a svuotare.
     *
     * In codici_falliti finiscono le anagrafiche il cui DETTAGLIO non e'
     * arrivato: sono note, quindi le si puo' escludere dalla cancellazione
     * invece di perderle.
     *
     * @return array{righe: array<int, array<string, mixed>>, saldi: array<int, array<string, mixed>>, codici_falliti: array<int, int>}|null
     */
    private function raccogli(EurekaClient $client, Tenant $tenant, string $tipo): ?array
    {
        $fornitori = $tipo === EurekaPartitaAperta::TIPO_FORNITORE;

        $saldi = $client->saldiPartiteAperte($fornitori);

        if ($saldi === null) {
            return null;
        }

        if ($saldi === []) {
            return ['righe' => [], 'saldi' => [], 'codici_falliti' => []];
        }

        $adesso = now();
        $nomiPerCodice = [];
        $saldiDichiarati = [];

        foreach ($saldi as $saldo) {
            $codice = (int) $saldo['id_nominativo'];
            $nomiPerCodice[$codice] = $saldo['nominativo'] ?? null;

            $saldiDichiarati[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'tipo' => $tipo,
                'gestionale_code' => $codice,
                'ragione_sociale' => $saldo['nominativo'] ?? null,
                'saldo' => round((float) ($saldo['saldo'] ?? 0), 2),
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ];
        }

        $dettagli = $client->partiteAperte(array_keys($nomiPerCodice), $fornitori);
        $codiciFalliti = array_map('intval', $client->chiaviPooledFallite());

        // Il collegamento al Customer si risolve in un colpo solo: una query
        // per codice sarebbe una query per riga.
        $customerPerCodice = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('gestionale_code', array_keys($nomiPerCodice))
            ->pluck('id', 'gestionale_code')
            ->all();

        $righe = [];

        foreach ($dettagli as $codice => $partite) {
            // Le anagrafiche il cui dettaglio e' fallito non producono righe:
            // le loro restano quelle dell'import precedente.
            if (in_array((int) $codice, $codiciFalliti, true)) {
                continue;
            }

            foreach ($partite as $partita) {
                $saldo = round((float) ($partita['saldo_partita'] ?? 0), 2);

                // Partite a saldo zero: chiuse ma ancora elencate. Non sono
                // esposizione e falserebbero i conteggi.
                if ($saldo === 0.0) {
                    continue;
                }

                $righe[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'tipo' => $tipo,
                    'gestionale_code' => (int) $codice,
                    'customer_id' => $customerPerCodice[(int) $codice] ?? null,
                    'ragione_sociale' => $nomiPerCodice[(int) $codice] ?? null,
                    'anno' => (int) ($partita['anno'] ?? 0),
                    'numero_fattura' => $partita['numero_fattura'] ?? null,
                    'data_fattura' => $this->data($partita['data_fattura'] ?? null),
                    'data_scadenza' => $this->scadenza($partita['movimenti'] ?? []),
                    'tipo_pagamento' => $this->tipoPagamento($partita['movimenti'] ?? []),
                    'saldo' => $saldo,
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ];
            }
        }

        return ['righe' => $righe, 'saldi' => $saldiDichiarati, 'codici_falliti' => $codiciFalliti];
    }

    /**
     * Scadenza di riferimento: la piu' recente fra i movimenti a dare, cioe'
     * la data entro cui l'intera fattura avrebbe dovuto essere pagata. I
     * movimenti ad avere (gli incassi) non dicono a quale rata si
     * riferiscono, quindi con un pagamento rateizzato non si puo' sapere
     * quale scadenza sia ancora scoperta: prendendo l'ultima, una partita
     * risulta scaduta solo quando lo e' senza ambiguita'.
     *
     * @param  array<int, array<string, mixed>>  $movimenti
     */
    private function scadenza(array $movimenti): ?string
    {
        $date = [];

        foreach ($movimenti as $movimento) {
            if ((float) ($movimento['dare'] ?? 0) <= 0) {
                continue;
            }

            if ($data = $this->data($movimento['data_scadenza'] ?? null)) {
                $date[] = $data;
            }
        }

        return $date === [] ? null : max($date);
    }

    /**
     * Modalita' di pagamento della partita, presa dal movimento della
     * fattura (quello a dare) e non dagli incassi: un pagamento parziale
     * puo' arrivare per un canale diverso da quello concordato, e quello che
     * serve a chi sollecita e' come la fattura ANDAVA pagata.
     *
     * @param  array<int, array<string, mixed>>  $movimenti
     */
    private function tipoPagamento(array $movimenti): ?string
    {
        foreach ($movimenti as $movimento) {
            if ((float) ($movimento['dare'] ?? 0) > 0 && filled($movimento['tipo_pagamento'] ?? null)) {
                return (string) $movimento['tipo_pagamento'];
            }
        }

        // Le note di credito hanno solo movimenti ad avere: meglio la loro
        // modalita' che niente.
        foreach ($movimenti as $movimento) {
            if (filled($movimento['tipo_pagamento'] ?? null)) {
                return (string) $movimento['tipo_pagamento'];
            }
        }

        return null;
    }

    private function data(mixed $valore): ?string
    {
        if (blank($valore)) {
            return null;
        }

        try {
            return Carbon::parse((string) $valore)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
