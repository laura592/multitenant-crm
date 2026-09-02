<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Gestionale\RegistroSync;
use Illuminate\Console\Command;

/**
 * Un comando solo che riporta il CRM in pari con Eureka.
 *
 * Serve quando il cron e' stato fermo (il blocco PHP 8.2 di agosto/settembre
 * 2026 ha tenuto ferma la produzione per giorni) o dopo aver rimesso in piedi
 * un ambiente: al posto di ricordarsi otto comandi e il loro ordine, se ne
 * lancia uno.
 *
 * L'ordine NON e' quello degli orari nello schedule, e' quello delle
 * dipendenze:
 *  - il catalogo prima dei rapportini, o le righe articolo non trovano il
 *    materiale a cui agganciarsi;
 *  - i prezzi subito dopo, cosi' le righe importate nascono gia' valorizzate;
 *  - gestionale:sync per ULTIMO, perche' le proposte di doppione confrontano
 *    i rapportini nostri con quelli importati: girando prima, confronterebbe
 *    con quelli di ieri e non proporrebbe niente.
 *
 * Best-effort come il resto dell'integrazione: un passo che fallisce non
 * ferma gli altri (Eureka ha 500 a raffica su query identiche, vedi
 * EurekaClient), ma l'esito finale li elenca e il comando esce diverso da
 * zero, cosi' il cron se ne accorge.
 */
class SincronizzaTuttoEureka extends Command
{
    protected $signature = 'eureka:sincronizza-tutto
        {--tenant=       : Slug tenant (default: tenant master)}
        {--da=           : Data inizio per i rapportini, YYYY-MM-DD (default: ultimi 120 giorni)}
        {--dry-run       : Passa --dry-run ai passi che lo prevedono}
        {--salta=*       : Nomi di passi da saltare, es. --salta=fatture}';

    protected $description = 'Riporta il CRM in pari con Eureka: catalogo, prezzi, rapportini, contabilita e anagrafiche';

    private const OPERAZIONE = 'sincronizza-tutto';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');
        $salta = array_map('strtolower', (array) $this->option('salta'));
        $da = $this->option('da') ?: now()->subDays(120)->toDateString();

        $passi = $this->passi($tenant->slug, $da, $dryRun);

        RegistroSync::avvio(self::OPERAZIONE, [
            'tenant' => $tenant->slug,
            'rapportini_dal' => $da,
            'dry_run' => $dryRun,
            'saltati' => $salta,
        ]);

        $esiti = [];
        $inizio = microtime(true);

        foreach ($passi as $nome => [$comando, $argomenti]) {
            if (in_array($nome, $salta, true)) {
                $this->line("· {$nome}: saltato");
                $esiti[$nome] = 'saltato';

                continue;
            }

            $this->newLine();
            $this->info("▸ {$nome} ({$comando})");
            $t = microtime(true);

            try {
                $codice = $this->call($comando, $argomenti);
            } catch (\Throwable $e) {
                // Un passo che esplode non deve portarsi via gli altri sette:
                // meglio sette ottavi di allineamento che niente.
                $codice = 1;
                $this->error("  {$e->getMessage()}");
                RegistroSync::problema(self::OPERAZIONE, "{$nome} interrotto", ['errore' => $e->getMessage()]);
            }

            $durata = round(microtime(true) - $t, 1);
            $esiti[$nome] = $codice === self::SUCCESS ? "ok ({$durata}s)" : "FALLITO ({$durata}s)";

            RegistroSync::movimento(self::OPERAZIONE, "{$nome} concluso", [
                'comando' => $comando,
                'esito' => $codice === self::SUCCESS ? 'ok' : 'fallito',
                'secondi' => $durata,
            ]);
        }

        $falliti = array_keys(array_filter($esiti, fn (string $e) => str_starts_with($e, 'FALLITO')));

        $this->newLine();
        $this->table(['Passo', 'Esito'], collect($esiti)->map(fn ($e, $n) => [$n, $e])->values()->all());

        RegistroSync::esito(self::OPERAZIONE, [
            'tenant' => $tenant->slug,
            'secondi' => round(microtime(true) - $inizio, 1),
            'falliti' => $falliti,
        ]);

        if ($falliti !== []) {
            $this->error('Passi falliti: '.implode(', ', $falliti).'. Il log e in storage/logs/gestionale-'.now()->toDateString().'.log');

            return self::FAILURE;
        }

        $this->info('Tutto allineato.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function passi(string $tenant, string $da, bool $dryRun): array
    {
        $prova = $dryRun ? ['--dry-run' => true] : [];

        return [
            // Prima il catalogo: le righe dei rapportini devono trovare
            // l'articolo a cui agganciarsi.
            'catalogo' => ['eureka:sweep-materials-catalog', ['--tenant' => $tenant] + $prova],
            'prezzi' => ['eureka:refresh-material-prices', ['--tenant' => $tenant] + $prova],
            // --with-detail non e' opzionale: senza, le schede arrivano senza
            // righe articolo e con la data documento al posto di quella
            // dell'appuntamento.
            'rapportini' => ['eureka:import-service-reports', [
                '--tenant' => $tenant, '--from' => $da, '--with-detail' => true,
            ] + $prova],
            'partite-aperte' => ['eureka:import-partite-aperte', ['--tenant' => $tenant]],
            'fatture' => ['eureka:import-fatture', ['--tenant' => $tenant]],
            'kpi' => ['eureka:import-kpi-contabili', ['--tenant' => $tenant]],
            'paganti' => ['eureka:apply-machine-billing-payer', ['--tenant' => $tenant] + $prova],
            // Per ultimo: le proposte di doppione confrontano i nostri
            // rapportini con quelli appena importati.
            'anagrafiche' => ['gestionale:sync', []],
        ];
    }

    private function resolveTenant(): Tenant
    {
        $slug = trim((string) $this->option('tenant'));

        return $slug !== ''
            ? Tenant::query()->where('slug', $slug)->firstOrFail()
            : Tenant::query()->where('is_master', true)->firstOrFail();
    }
}
