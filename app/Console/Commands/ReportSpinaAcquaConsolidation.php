<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Report di sola lettura (nessuna scrittura sul DB) per preparare il
 * consolidamento dei placeholder MachineUnit "Impianto birra/vino/acqua/Selz"
 * in un'unica MachineUnit per colonna fisica reale (vedi analisi 2026-08-19:
 * la scadenza/composizione resta su MaintenanceSchedule, non su MachineUnit -
 * qui si vuole solo smettere di avere 2-3 macchine finte per la stessa
 * colonna). Incrocia due fonti indipendenti per cliente:
 *
 * - i placeholder MachineUnit manuali gia' presenti (model_name libero)
 * - lo storico articoli Eureka spina/acqua usati nei rapportini
 *   (service_reports.machine_product_id), l'unica traccia strutturata che
 *   Eureka lascia per questi impianti - non hanno mai una matricola (vedi
 *   GestionaleSyncRunner::importInstalledMachines, che scarta le righe senza
 *   matricola), quindi non arrivano mai come MachineUnit source=eureka.
 *
 * Un cliente e' "pulito" (consolidamento proponibile quasi automaticamente)
 * se lo storico rapportini usa sempre lo stesso articolo spina/acqua; se ne
 * usa piu' di uno, puo' trattarsi di piu' impianti fisici reali (es. camping
 * con piu' punti spina) o solo di scelte incoerenti dell'articolo da parte di
 * chi compilava - va deciso a mano, non deducibile con certezza dai soli
 * dati.
 */
class ReportSpinaAcquaConsolidation extends Command
{
    protected $signature = 'machine-units:report-spina-acqua-consolidation
        {--export= : Percorso CSV (relativo a storage/app) dove salvare il report completo}';

    protected $description = 'Report di sola lettura: per ogni cliente con impianti spina/acqua, confronta i placeholder MachineUnit attuali con lo storico articoli Eureka dai rapportini, per preparare il consolidamento manuale';

    private const KEYWORD_PATTERN = '%SPINA%|%ACQUA%|%COLONNA%|%CASETTA%|%VINO%|%BIRRA%|%SELZ%';

    public function handle(): int
    {
        $customerIds = $this->relevantCustomerIds();

        if ($customerIds->isEmpty()) {
            $this->info('Nessun cliente con impianti spina/acqua trovato.');

            return self::SUCCESS;
        }

        $rows = $customerIds->map(fn (string $customerId) => $this->buildRow($customerId))
            ->sortBy(fn (array $row) => [$row['categoria'] === 'da_rivedere' ? 0 : 1, $row['cliente']])
            ->values();

        $pulito = $rows->where('categoria', 'pulito')->count();
        $daRivedere = $rows->where('categoria', 'da_rivedere')->count();

        $this->info("Clienti analizzati: {$rows->count()} — puliti (auto-consolidabili): {$pulito}, da rivedere a mano: {$daRivedere}.");

        $this->table(
            ['Cliente', 'Categoria', 'MachineUnit attuali', 'Storico articoli Eureka (da rapportini)'],
            $rows->map(fn (array $row) => [
                $row['cliente'],
                $row['categoria'],
                $row['machine_units_attuali'],
                $row['storico_articoli'],
            ]),
        );

        if ($exportPath = $this->option('export')) {
            $this->exportCsv($exportPath, $rows);
            $this->info('Report completo esportato in storage/app/'.$exportPath);
        }

        return self::SUCCESS;
    }

    /**
     * Clienti rilevanti: hanno un placeholder MachineUnit con nome
     * riconducibile a spina/acqua, oppure hanno almeno un rapportino il cui
     * articolo (machine_product_id) e' della stessa famiglia — unione delle
     * due fonti, cosi' non si perde un cliente che ha solo l'una o solo
     * l'altra.
     */
    private function relevantCustomerIds(): \Illuminate\Support\Collection
    {
        $fromMachineUnits = MachineUnit::query()
            ->whereNotNull('current_customer_id')
            ->where(function ($query) {
                foreach (explode('|', self::KEYWORD_PATTERN) as $pattern) {
                    $query->orWhere('model_name', 'like', $pattern);
                }
            })
            ->pluck('current_customer_id');

        $fromServiceReports = DB::table('service_reports')
            ->join('products', 'products.id', '=', 'service_reports.machine_product_id')
            ->where(function ($query) {
                foreach (explode('|', self::KEYWORD_PATTERN) as $pattern) {
                    $query->orWhere('products.name', 'like', $pattern);
                }
            })
            ->whereNotNull('service_reports.customer_id')
            ->pluck('service_reports.customer_id');

        return $fromMachineUnits->merge($fromServiceReports)->unique()->values();
    }

    /**
     * @return array{cliente: string, categoria: string, machine_units_attuali: string, storico_articoli: string}
     */
    private function buildRow(string $customerId): array
    {
        $customer = Customer::find($customerId);

        $machineUnits = MachineUnit::query()
            ->where('current_customer_id', $customerId)
            ->where(function ($query) {
                foreach (explode('|', self::KEYWORD_PATTERN) as $pattern) {
                    $query->orWhere('model_name', 'like', $pattern);
                }
            })
            ->get(['id', 'model_name', 'type', 'source']);

        $articleHistory = DB::table('service_reports')
            ->join('products', 'products.id', '=', 'service_reports.machine_product_id')
            ->where('service_reports.customer_id', $customerId)
            ->where(function ($query) {
                foreach (explode('|', self::KEYWORD_PATTERN) as $pattern) {
                    $query->orWhere('products.name', 'like', $pattern);
                }
            })
            ->selectRaw('products.name, COUNT(*) as rapportini')
            ->groupBy('products.name')
            ->orderByDesc('rapportini')
            ->get();

        $categoria = $articleHistory->count() <= 1 ? 'pulito' : 'da_rivedere';

        return [
            'cliente' => $customer?->company_name ?? $customer?->full_name ?? $customerId,
            'categoria' => $categoria,
            'machine_units_attuali' => $machineUnits->isEmpty()
                ? '— (nessun placeholder, solo rapportini)'
                : $machineUnits->map(fn (MachineUnit $m) => ($m->model_name ?? 'senza nome').($m->type ? " [{$m->type}]" : '').' — '.$m->source)->implode(' | '),
            'storico_articoli' => $articleHistory->isEmpty()
                ? '— (nessun rapportino con articolo spina/acqua)'
                : $articleHistory->map(fn ($a) => "{$a->name} ({$a->rapportini})")->implode(' | '),
        ];
    }

    private function exportCsv(string $path, \Illuminate\Support\Collection $rows): void
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['Cliente', 'Categoria', 'MachineUnit attuali', 'Storico articoli Eureka (da rapportini)']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['cliente'],
                $row['categoria'],
                $row['machine_units_attuali'],
                $row['storico_articoli'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Storage::put($path, $csv);
    }
}
