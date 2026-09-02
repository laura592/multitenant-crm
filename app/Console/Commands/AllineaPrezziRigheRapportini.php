<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Riempie con il listino le righe materiale che sono rimaste senza prezzo.
 *
 * Eureka manda spesso prezzo=0 sulle righe di intervento (CHIORD, le
 * installazioni, le disinstallazioni) e l'import lo traduceva in NULL: le
 * voci piu' fatturabili del rapportino restavano senza valore. Dal 02/09/2026
 * l'import usa il listino dell'articolo quando la riga non porta un prezzo;
 * questo comando fa lo stesso sullo storico gia' importato.
 *
 * Tocca SOLO le righe senza prezzo: un prezzo arrivato da Eureka e' il dato
 * buono e non si sovrascrive con il listino corrente, che nel frattempo puo'
 * essere cambiato.
 *
 * Comando e non migration: e' una scrittura su migliaia di righe economiche,
 * il deploy non deve farla da solo.
 */
class AllineaPrezziRigheRapportini extends Command
{
    protected $signature = 'eureka:allinea-prezzi-righe
        {--tenant=       : Slug tenant (default: tenant master)}
        {--dry-run       : Mostra il diff senza scrivere nulla}
        {--force         : Non chiedere conferma}';

    protected $description = 'Riempie con il listino le righe dei rapportini rimaste senza prezzo';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');

        $righe = ServiceReportMaterial::query()
            ->whereNull('unit_cost_snapshot')
            ->whereHas('serviceReport', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->with('material')
            ->get();

        $recuperabili = $righe->filter(fn (ServiceReportMaterial $r) => (float) ($r->material->list_price ?? 0) > 0);

        if ($recuperabili->isEmpty()) {
            $this->info('Nessuna riga da riprezzare.');

            return self::SUCCESS;
        }

        $perArticolo = $recuperabili
            ->groupBy(fn (ServiceReportMaterial $r) => $r->material->code)
            ->map(fn ($gruppo) => [
                $gruppo->first()->material->code,
                $gruppo->count(),
                number_format((float) $gruppo->first()->material->list_price, 2, ',', '.').' €',
            ])
            ->sortByDesc(fn ($riga) => $riga[1])
            ->values();

        $this->line("Righe senza prezzo: {$righe->count()}, di cui riprezzabili dal listino: {$recuperabili->count()}");
        $this->table(['Articolo', 'Righe', 'Listino'], $perArticolo->take(15)->all());

        $senzaListino = $righe->count() - $recuperabili->count();

        if ($senzaListino > 0) {
            $this->warn("{$senzaListino} righe restano senza prezzo: l'articolo non ha listino.");
        }

        if ($dryRun) {
            $this->comment('Prova a vuoto: non e\' stato scritto nulla.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Riprezzo {$recuperabili->count()} righe?", false)) {
            $this->comment('Annullato.');

            return self::SUCCESS;
        }

        $scritte = 0;

        foreach ($recuperabili as $riga) {
            $prezzo = (float) $riga->material->list_price;

            $riga->update([
                'unit_cost_snapshot' => $prezzo,
                // Solo se manca: un importo arrivato da Eureka porta gli
                // sconti di riga gia' applicati e vale piu' del calcolo.
                'line_total_snapshot' => $riga->line_total_snapshot
                    ?? round($prezzo * (float) $riga->quantity, 2),
            ]);

            $scritte++;
        }

        $this->info("Righe riprezzate: {$scritte}.");

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
