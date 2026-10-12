<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Gestionale\DocumentiMacchina;
use App\Support\Gestionale\EurekaClient;
use App\Support\Gestionale\StessoPosto;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Eureka contro Eureka: l'elenco delle macchine installate contro i suoi
 * documenti (12/10/2026).
 *
 * Il gestionale tiene due verita' sullo stesso fatto. Una e'
 * /show/q/art_installati, l'elenco di cosa risulta installato presso un
 * cliente, con il riferimento alla bolla di consegna. L'altra sono i
 * documenti: fatture e bolle che nominano la matricola. Le due cose si
 * scollano, e quando si scollano nessuna delle due avvisa:
 *
 * - una macchina spostata con una bolla nuova resta nell'elenco del cliente
 *   di prima (il ritiro e' un DDT, e l'elenco non lo riflette);
 * - una macchina consegnata puo' non comparire affatto nell'elenco;
 * - l'elenco puo' tenerne una che i documenti hanno mandato altrove.
 *
 * Qui si leggono entrambe e si stampa dove non coincidono. Sola lettura:
 * nessuna scrittura, ne' sul CRM ne' su Eureka. Eureka e' sacrosanto — le
 * schede e i documenti non si toccano, si guardano.
 */
class DisallineamentiEureka extends Command
{
    protected $signature = 'macchine:disallineamenti-eureka
                            {--tenant=alex : slug del tenant}
                            {--limite=0 : ferma la lettura alle prime N matricole (per provare)}';

    protected $description = 'Confronta l\'elenco delle macchine installate di Eureka con i suoi documenti e stampa i disallineamenti';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();
        $client = new EurekaClient($tenant);

        if ($guasto = $client->nonDisponibile()) {
            $this->error('Eureka non risponde: '.$guasto);

            return self::FAILURE;
        }

        $clienti = Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->get(['id', 'company_name', 'gestionale_code', 'billing_customer_id']);

        $perCodice = $clienti->whereNotNull('gestionale_code')->keyBy(fn (Customer $c) => (int) $c->gestionale_code);
        $nomi = $clienti->pluck('company_name', 'id');
        $stessoPosto = StessoPosto::per($clienti);

        // 1. L'elenco delle macchine installate, cliente per cliente.
        $this->info('Leggo l\'elenco delle macchine installate di '.$perCodice->count().' clienti...');

        $parametri = $perCodice->mapWithKeys(fn (Customer $c) => [$c->id => ['q' => (int) $c->gestionale_code]])->all();
        $risposte = $client->pooledGet('/show/q/art_installati', $parametri);
        $falliti = $client->chiaviPooledFallite();

        for ($tentativo = 0; $tentativo < 2 && $falliti !== []; $tentativo++) {
            $this->line('  riprovo '.count($falliti).' clienti...');
            usleep(2_000_000);
            $ripetute = $client->pooledGet('/show/q/art_installati', array_intersect_key($parametri, array_flip($falliti)));
            $ancora = $client->chiaviPooledFallite();

            foreach ($ripetute as $chiave => $valore) {
                if (! in_array($chiave, $ancora, true)) {
                    $risposte[$chiave] = $valore;
                }
            }

            $falliti = $ancora;
        }

        if ($falliti !== []) {
            $this->error('Eureka non ha risposto per '.count($falliti).' clienti: senza l\'elenco intero il confronto direbbe bugie.');

            return self::FAILURE;
        }

        $installate = [];

        foreach ($risposte as $customerId => $righe) {
            foreach ((array) $righe as $riga) {
                $matricola = trim((string) ($riga['matricola'] ?? ''));

                if ($matricola === '' || preg_match('/^0+$/', $matricola)) {
                    continue;
                }

                $installate[MachineUnit::chiaveMatricola($matricola)] = [
                    'matricola' => $matricola,
                    'cliente' => (string) $customerId,
                    'bolla' => (int) ($riga['numero_doc_t23'] ?? 0),
                    'data' => $this->data($riga['data_documento'] ?? null),
                    'articolo' => trim((string) ($riga['desc_articolo_1'] ?? $riga['articolo'] ?? '')),
                ];
            }
        }

        $this->line('  '.count($installate).' matricole risultano installate presso qualcuno.');

        if ((int) $this->option('limite') > 0) {
            $installate = array_slice($installate, 0, (int) $this->option('limite'), true);
        }

        // 2. I documenti di quelle stesse matricole.
        $this->info('Cerco i documenti di '.count($installate).' matricole...');

        $esito = DocumentiMacchina::perMatricole($tenant, collect($installate)->pluck('matricola'));

        if (($esito['guasto'] ?? null) !== null) {
            $this->error('Eureka non risponde: '.$esito['guasto']);

            return self::FAILURE;
        }

        if ($esito['falliti'] !== []) {
            $this->warn(count($esito['falliti']).' matricole senza risposta: restano fuori dal confronto.');
        }

        // 3. Il confronto.
        $spostate = [];
        $senzaDocumenti = [];
        $paganti = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('billing_customer_id')
            ->pluck('billing_customer_id', 'serial_number')
            ->mapWithKeys(fn ($pagante, $matricola) => [MachineUnit::chiaveMatricola((string) $matricola) => $pagante]);

        foreach ($installate as $chiave => $riga) {
            $movimenti = $esito['movimenti'][$chiave] ?? [];

            if ($movimenti === []) {
                $senzaDocumenti[] = [
                    $riga['matricola'],
                    DisplayName::titleCase($nomi[$riga['cliente']] ?? '?'),
                    $riga['data']?->format('d/m/Y') ?: '—',
                    mb_substr($riga['articolo'], 0, 34),
                ];

                continue;
            }

            $ultimo = end($movimenti);
            $clienteDocumento = $perCodice->get((int) $ultimo['codice_cliente'])?->id;

            if ($stessoPosto->uguali($clienteDocumento, $riga['cliente'], $paganti->get($chiave))) {
                continue;
            }

            // Un documento PIU' VECCHIO della consegna non contraddice
            // niente: e' il passato della macchina. Sulla matricola 1187426
            // l'elenco dava Hotel Miramare Paulin da maggio 2025 e l'ultimo
            // documento la Pasticceria Al Ponte di marzo 2023 — due anni
            // prima, quindi la bolla ha ragione e di disallineamento non ce
            // n'e'.
            if ($riga['data'] && $ultimo['data']->lte($riga['data'])) {
                continue;
            }

            $spostate[] = [
                $riga['matricola'],
                DisplayName::titleCase($nomi[$riga['cliente']] ?? '?').($riga['bolla'] ? ' (bolla '.$riga['bolla'].')' : ''),
                $riga['data']?->format('d/m/Y') ?: '—',
                $clienteDocumento
                    ? DisplayName::titleCase($nomi[$clienteDocumento] ?? '?')
                    : 'codice '.$ultimo['codice_cliente'].' — non in anagrafica CRM',
                $ultimo['tipo'].' n.'.$ultimo['numero'].' del '.$ultimo['data']->format('d/m/Y'),
            ];
        }

        $this->line('');
        $this->line('L\'ELENCO INSTALLATI E I DOCUMENTI DICONO CLIENTI DIVERSI ('.count($spostate).')');

        if ($spostate === []) {
            $this->line('  nessuna');
        } else {
            usort($spostate, fn (array $a, array $b) => $b[4] <=> $a[4]);
            $this->table(['Matricola', 'Installata presso', 'Dal', 'Ultimo documento la da\' a', 'Documento'], $spostate);
        }

        $this->line('');
        $this->line('RISULTANO INSTALLATE MA NESSUN DOCUMENTO LE NOMINA ('.count($senzaDocumenti).')');

        if ($senzaDocumenti === []) {
            $this->line('  nessuna');
        } else {
            $this->table(['Matricola', 'Installata presso', 'Dal', 'Articolo'], array_slice($senzaDocumenti, 0, 40));

            if (count($senzaDocumenti) > 40) {
                $this->line('  ... e altre '.(count($senzaDocumenti) - 40).'.');
            }
        }

        return self::SUCCESS;
    }

    private function data(mixed $valore): ?Carbon
    {
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($valore)->startOfDay(), null, false);
    }
}
