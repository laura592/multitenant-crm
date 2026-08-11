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
 * comando li mette in pari.
 *
 * Per tenant+anno, i rapportini eureka vengono rinumerati DA CAPO in ordine
 * di intervention_date (non di created_at, che riflette solo quando la riga
 * e' stata importata — per uno storico puo' essere anni dopo l'intervento
 * vero) e non dal numero che avevano prima: una prima versione di questo
 * comando assegnava i numeri uno alla volta in ordine di creazione o solo
 * correggeva l'anno lasciando la sequenza interna nell'ordine sbagliato,
 * con risultati come "Numero 111" su una data piu' vecchia di "Numero 90"
 * (vedi thread rapportini/Eureka). La sequenza riparte subito dopo il
 * numero piu' alto gia' in uso per quel tenant+anno da un rapportino NON
 * eureka (nato in CRM): quelli non si toccano mai, la loro numerazione
 * segue l'ordine di creazione/invio, non la data dell'intervento (stesso
 * comportamento di ServiceReport::nextNumberForTenant()).
 *
 * Pensato per una correzione storica una tantum: rieseguibile senza
 * duplicare numeri, ma se nel frattempo sono stati importati nuovi
 * rapportini eureka per lo stesso tenant+anno, la sequenza per quell'anno
 * viene ricalcolata e i numeri eureka esistenti possono cambiare di nuovo.
 */
class BackfillServiceReportCrmNumbers extends Command
{
    protected $signature = 'service-reports:backfill-crm-numbers
        {--dry-run : Mostra quanti rapportini verrebbero aggiornati senza scrivere nulla}';

    protected $description = "Rinumera i rapportini importati da Eureka con un numero CRM (RT-...) coerente con l'ordine delle date di intervento, spostando il numero Eureka in gestionale_number";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $groups = ServiceReport::withTrashed()
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->get(['id', 'tenant_id', 'intervention_date', 'number', 'gestionale_number', 'created_at'])
            ->groupBy(fn (ServiceReport $report) => $report->tenant_id.'|'.$report->intervention_date->year);

        if ($groups->isEmpty()) {
            $this->info('Niente da fare: nessun rapportino Eureka trovato.');

            return self::SUCCESS;
        }

        // Calcola tutti i cambi prima di scrivere: number ha un vincolo
        // di unicita' per tenant, e la nuova sequenza e' una permutazione
        // della vecchia (stessi numeri, ordine diverso) — scrivendo riga per
        // riga si finisce quasi certamente ad assegnare un numero che un'altra
        // riga del gruppo ha ancora, non ancora aggiornata (constraint
        // violation). Le righe cambiate vanno quindi spostate su un valore
        // temporaneo univoco prima di ricevere il numero finale.
        $changes = [];
        $unchanged = 0;

        foreach ($groups as $key => $reports) {
            [$tenantId, $year] = explode('|', $key);
            $prefix = "RT-{$year}-";

            $baseline = ServiceReport::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('source', '!=', ServiceReport::SOURCE_EUREKA)
                ->where('number', 'like', "{$prefix}%")
                ->get(['number'])
                ->max(fn ($report) => (int) substr($report->number, -4)) ?? 0;

            $sorted = $reports->sortBy([
                ['intervention_date', 'asc'],
                ['created_at', 'asc'],
            ])->values();

            foreach ($sorted as $i => $report) {
                $newNumber = $prefix.str_pad((string) ($baseline + $i + 1), 4, '0', STR_PAD_LEFT);

                if ($newNumber === $report->number) {
                    $unchanged++;

                    continue;
                }

                $changes[] = [
                    'id' => $report->id,
                    'number' => $newNumber,
                    // Se non era mai stato migrato (number ancora in
                    // formato SL-...), e' quello il numero Eureka da
                    // salvare; altrimenti gestionale_number e' gia'
                    // corretto da un giro precedente e resta com'e'.
                    'gestionale_number' => $report->gestionale_number ?? $report->number,
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
                    ->update(['number' => $change['number'], 'gestionale_number' => $change['gestionale_number']]);
            }
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
