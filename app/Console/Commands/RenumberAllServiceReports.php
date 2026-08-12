<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\ServiceReport;
use Illuminate\Console\Command;

/**
 * A differenza di service-reports:backfill-crm-numbers (che rinumera solo i
 * rapportini Eureka e non tocca mai quelli nati in CRM), questo comando
 * unifica TUTTI i rapportini di un tenant+anno — manuali ed Eureka insieme —
 * in un'unica sequenza progressiva ordinata per data intervento. Richiesto
 * esplicitamente: l'utente vuole piena coerenza nel CRM anche a costo di
 * cambiare il numero di rapportini gia' "inviato" (gia' consegnati al
 * cliente con il vecchio numero).
 *
 * Stessa tecnica di swap su valore temporaneo di backfill-crm-numbers (il
 * vincolo di unicita' e' su tenant_id+number, e la nuova sequenza e' una
 * permutazione della vecchia).
 */
class RenumberAllServiceReports extends Command
{
    protected $signature = 'service-reports:renumber-all
        {--dry-run : Mostra quanti rapportini verrebbero rinumerati senza scrivere nulla}';

    protected $description = "Rinumera TUTTI i rapportini (manuali ed Eureka) in un'unica sequenza progressiva per tenant+anno, ordinata per data intervento";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $groups = ServiceReport::withTrashed()
            ->get(['id', 'tenant_id', 'intervention_date', 'number', 'created_at'])
            ->groupBy(fn (ServiceReport $report) => $report->tenant_id.'|'.$report->intervention_date->year);

        $changes = [];
        $unchanged = 0;

        foreach ($groups as $key => $reports) {
            [, $year] = explode('|', $key);
            $prefix = "RT-{$year}-";

            $sorted = $reports->sortBy([
                ['intervention_date', 'asc'],
                ['created_at', 'asc'],
            ])->values();

            foreach ($sorted as $i => $report) {
                $newNumber = $prefix.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);

                if ($newNumber === $report->number) {
                    $unchanged++;

                    continue;
                }

                $changes[] = [
                    'id' => $report->id,
                    'number' => $newNumber,
                    'old_number' => $report->number,
                ];
            }
        }

        if (! $dryRun) {
            foreach ($changes as $change) {
                ServiceReport::withTrashed()->whereKey($change['id'])
                    ->update(['number' => 'TMP-'.$change['id']]);
            }

            foreach ($changes as $change) {
                ServiceReport::withTrashed()->whereKey($change['id'])
                    ->update(['number' => $change['number']]);
            }

            // "Generato da rapportino RT-..." nella tabella lavaggi (vedi
            // ReconstructLavaggiHistory/LinkLavaggiToServiceReports) incorpora
            // il numero come testo statico: senza questo aggiornamento
            // resterebbe con il vecchio numero dopo la rinumerazione.
            $byId = collect($changes)->keyBy('id');
            Lavaggio::whereIn('service_report_id', $byId->keys())
                ->where('descrizione', 'like', 'Generato da rapportino %')
                ->get(['id', 'service_report_id'])
                ->each(function (Lavaggio $lavaggio) use ($byId) {
                    $lavaggio->update([
                        'descrizione' => 'Generato da rapportino '.$byId[$lavaggio->service_report_id]['number'],
                    ]);
                });
        }

        $this->info(sprintf(
            '%sRinumerati: %d. Già corretti: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            count($changes),
            $unchanged,
        ));

        return self::SUCCESS;
    }
}
