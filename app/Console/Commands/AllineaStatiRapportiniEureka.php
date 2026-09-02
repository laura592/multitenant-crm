<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Riporta a "in_gestionale" lo stato dei rapportini arrivati da Eureka.
 *
 * Fino al 02/09/2026 l'import mappava eureka_stato_documento=10 ("Archiviato") su
 * "inviato" e tutto il resto su "bozza". Era sbagliato: nel CRM "inviato"
 * significa spedito via mail AL CLIENTE dall'azione di invio, cosa mai
 * avvenuta per queste schede, e chi riapriva il rapportino non sapeva che il
 * documento va corretto su Eureka e non qui. Lo stato grezzo del gestionale
 * resta comunque in eureka_stato_documento/eureka_stato_label.
 *
 * Una tantum sullo storico: l'import corretto assegna gia' il valore giusto
 * alle schede nuove.
 *
 * Deliberatamente un comando e non una migration: e' una riscrittura di dati
 * su migliaia di righe, e il deploy non deve cambiare stati da solo. Va
 * lanciato a mano, mostra il diff e chiede conferma.
 */
class AllineaStatiRapportiniEureka extends Command
{
    protected $signature = 'eureka:allinea-stati-rapportini
        {--tenant=       : Slug tenant (default: tenant master)}
        {--dry-run       : Mostra il diff senza scrivere nulla}
        {--force         : Non chiedere conferma (per l\'uso non interattivo)}';

    protected $description = 'Porta a "In gestionale" lo stato dei rapportini importati da Eureka';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');

        // Non solo le schede importate: anche un rapportino nostro unito a una
        // scheda VIVE ormai su Eureka. Quelli uniti prima che confermaDuplicato()
        // cambiasse lo stato (02/09/2026) erano rimasti "completato" pur avendo
        // il collegamento — RT-2026-0580, 0581, 0622.
        $daCambiare = ServiceReport::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '<>', 'in_gestionale')
            ->where(fn ($q) => $q
                ->where('source', ServiceReport::SOURCE_EUREKA)
                ->orWhereNotNull('eureka_service_report_id'));

        $conteggi = (clone $daCambiare)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $totale = (int) $conteggi->sum();

        if ($totale === 0) {
            $this->info('Gli stati sono gia\' allineati: niente da fare.');

            return self::SUCCESS;
        }

        $this->line("Rapportini importati da Eureka con uno stato diverso: {$totale}");
        $this->table(
            ['Stato attuale', 'Rapportini', 'Diventa'],
            $conteggi->map(fn (int $n, string $stato) => [$stato, $n, 'in_gestionale'])->values()->all(),
        );

        // "in gestionale" dice dove VIVE il documento, non se e' definitivo,
        // quindi si applica anche alle schede non chiuse su Eureka. Ma
        // in_gestionale conta come chiuso (ServiceReport::CLOSED_STATUSES),
        // quindi va detto quante sono — distinguendo quelle che Eureka
        // dichiara aperte da quelle su cui non abbiamo il dato, che sono la
        // maggioranza (importate prima che si leggesse stato_documento) e non
        // vanno spacciate per "non archiviate".
        $aperte = (clone $daCambiare)
            ->whereNotNull('eureka_stato_documento')
            ->where('eureka_stato_documento', '<>', 10)
            ->count();

        $senzaDato = (clone $daCambiare)->whereNull('eureka_stato_documento')->count();

        if ($aperte > 0) {
            $this->warn("Di questi, {$aperte} risultano ancora aperti su Eureka: diventeranno comunque chiusi qui.");
        }

        if ($senzaDato > 0) {
            $this->line("({$senzaDato} importati prima che si leggesse lo stato Eureka: dato non disponibile.)");
        }

        if ($dryRun) {
            $this->comment('Prova a vuoto: non e\' stato scritto nulla.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Aggiorno {$totale} rapportini?", false)) {
            $this->comment('Annullato.');

            return self::SUCCESS;
        }

        $aggiornati = $daCambiare->update(['status' => 'in_gestionale']);

        $this->info("Aggiornati: {$aggiornati}.");

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
