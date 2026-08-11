<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use Illuminate\Console\Command;

/**
 * Prima di questo comando, un rapportino ripescato da Eureka
 * (source=eureka) aveva come unico "number" quello in formato Eureka
 * ("SL-.../anno") — non aveva mai avuto un numero interno CRM, a differenza
 * di un rapportino nato in CRM (RT-...). ImportEurekaServiceReports ora
 * assegna un RT-... anche ai nuovi import (vedi
 * ImportEurekaServiceReports::resolveGestionaleNumber()), ma i rapportini
 * gia' importati prima di questa modifica restano con solo l'SL-...: questo
 * comando li mette in pari, uno alla volta (stesso schema RT-{anno}-{seq}
 * di ServiceReport::nextNumberForTenant(), cosi' restano indistinguibili da
 * un rapportino nato oggi in CRM).
 */
class BackfillServiceReportCrmNumbers extends Command
{
    protected $signature = 'service-reports:backfill-crm-numbers
        {--dry-run : Mostra quanti rapportini verrebbero aggiornati senza scrivere nulla}';

    protected $description = 'Assegna un numero CRM (RT-...) ai rapportini importati da Eureka che ne sono ancora privi, spostando il numero Eureka in gestionale_number';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $reports = ServiceReport::withTrashed()
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->whereNull('gestionale_number')
            ->orderBy('created_at')
            ->get();

        if ($reports->isEmpty()) {
            $this->info('Niente da fare: nessun rapportino Eureka senza numero CRM.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%sTrovati %d rapportini da aggiornare.', $dryRun ? '[DRY RUN] ' : '', $reports->count()));

        $updated = 0;

        $this->withProgressBar($reports, function (ServiceReport $report) use ($dryRun, &$updated): void {
            $gestionaleNumber = $report->number;
            $crmNumber = ServiceReport::nextNumberForTenant($report->tenant_id);

            if (! $dryRun) {
                $report->forceFill([
                    'gestionale_number' => $gestionaleNumber,
                    'number' => $crmNumber,
                ])->save();
            }

            $updated++;
        });

        $this->newLine(2);
        $this->info(sprintf('%sAggiornati: %d.', $dryRun ? '[DRY RUN] ' : '', $updated));

        return self::SUCCESS;
    }
}
