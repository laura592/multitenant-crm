<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Libera i numeri CRM rimasti appesi alle copie gia' unite.
 *
 * Dal 03/09/2026 confermare un doppione libera da se' il numero della copia
 * (ServiceReport::liberaNumero()). Le unioni fatte PRIMA hanno invece
 * lasciato il numero occupato da un rapportino archiviato: 61 buchi nella
 * serie del 2026 in locale.
 *
 * Una tantum per ambiente. Non rinumera niente: nessun rapportino vivo
 * cambia numero — rinumerare avrebbe spostato anche quelli gia' spediti ai
 * clienti, che il numero ce l'hanno stampato sul PDF.
 *
 * Tocca SOLO le copie che risultano davvero unite, cioe' quelle la cui
 * scheda Eureka appartiene ormai a un rapportino vivo. Un rapportino
 * archiviato per altri motivi tiene il suo numero: puo' essere ripescato, e
 * due rapportini con lo stesso numero sarebbero peggio di un buco.
 */
class LiberaNumeriRapportiniUniti extends Command
{
    protected $signature = 'rapportini:libera-numeri-uniti
        {--tenant=       : Slug tenant (default: tenant master)}
        {--dry-run       : Mostra il diff senza scrivere nulla}
        {--force         : Non chiedere conferma}';

    protected $description = 'Rimette in circolo i numeri delle copie unite prima del 03/09/2026';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');

        // Le schede Eureka che ormai appartengono a un rapportino vivo: e'
        // questo che distingue una copia UNITA da un rapportino archiviato
        // per altri motivi.
        $scheduleUnite = ServiceReport::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('eureka_service_report_id')
            ->pluck('eureka_service_report_id');

        $copie = ServiceReport::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('number', 'like', 'RT-%')
            ->whereIn('eureka_service_report_id', $scheduleUnite)
            ->orderBy('number')
            ->get();

        if ($copie->isEmpty()) {
            $this->info('Nessun numero da liberare.');

            return self::SUCCESS;
        }

        $this->line("Numeri ancora occupati da copie unite: {$copie->count()}");
        $this->table(
            ['Numero', 'Scheda gestionale', 'Diventa'],
            $copie->take(10)->map(fn (ServiceReport $r) => [
                $r->number,
                $r->gestionale_number ?: '—',
                'UNITO-'.($r->eureka_service_report_id ?? $r->id),
            ])->all(),
        );

        if ($copie->count() > 10) {
            $this->line('  … e altri '.($copie->count() - 10).'.');
        }

        $this->line('Nessun rapportino vivo cambia numero: i buchi si richiudono da soli ai prossimi import.');

        if ($dryRun) {
            $this->comment('Prova a vuoto: non e\' stato scritto nulla.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Libero {$copie->count()} numeri?", false)) {
            $this->comment('Annullato.');

            return self::SUCCESS;
        }

        foreach ($copie as $copia) {
            $copia->liberaNumero();
        }

        $this->info("Numeri liberati: {$copie->count()}.");
        $this->line('Prossimo numero assegnato: '.ServiceReport::nextNumberForTenant($tenant->id));

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
