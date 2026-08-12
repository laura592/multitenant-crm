<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use Illuminate\Console\Command;

/**
 * Una tantum: prima di questa correzione l'import Eureka mappava
 * intervention_date sulla data del documento ("data") invece che sulla data
 * vera dell'intervento ("sl_dataora_appuntamento") — quest'ultima veniva
 * comunque scaricata ma buttata in arrival_at, un campo legacy mai mostrato
 * da nessuna parte nell'app (vedi ImportEurekaServiceReports).
 *
 * Per i rapportini gia' importati la data giusta e' quindi gia' in
 * database: nessuna nuova chiamata a Eureka necessaria, basta spostare
 * arrival_at su intervention_date e salvare la vecchia intervention_date
 * (data documento) in gestionale_document_date. Dove arrival_at manca (import
 * storici fatti senza --with-detail) non c'e' altro dato disponibile:
 * intervention_date resta quella del documento, gestionale_document_date
 * viene comunque valorizzata con lo stesso valore per coerenza col nuovo
 * import.
 */
class BackfillServiceReportInterventionDates extends Command
{
    protected $signature = 'service-reports:backfill-intervention-dates
        {--dry-run : Mostra quanti rapportini verrebbero corretti senza scrivere nulla}';

    protected $description = "Corregge intervention_date dei rapportini Eureka gia' importati usando la data di appuntamento (arrival_at) invece della data documento";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $reports = ServiceReport::withTrashed()
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->get(['id', 'number', 'intervention_date', 'arrival_at', 'gestionale_document_date']);

        $updated = 0;
        $unchanged = 0;

        foreach ($reports as $report) {
            $documentDate = $report->intervention_date->toDateString();
            $appointmentDate = $report->arrival_at?->toDateString();

            $report->intervention_date = $appointmentDate ?? $documentDate;
            $report->gestionale_document_date = $documentDate;

            if (! $report->isDirty()) {
                $unchanged++;

                continue;
            }

            $updated++;

            if (! $dryRun) {
                $report->save();
            }
        }

        $this->info(sprintf(
            '%sCorretti: %d. Già corretti: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $updated,
            $unchanged,
        ));

        return self::SUCCESS;
    }
}
