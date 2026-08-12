<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export una tantum richiesto dopo la ricostruzione dello storico lavaggi
 * (vedi LinkLavaggiToServiceReports e ReconstructLavaggiHistory): tre fogli
 * -- clienti, rapportini collegati, storico per impianto -- pensati per una
 * revisione umana fuori dal CRM, non per un uso ricorrente.
 */
class ExportLavaggiStorico extends Command
{
    protected $signature = 'lavaggi:export-storico {--path=storage/app/lavaggi-storico.xlsx}';

    protected $description = "Esporta in Excel clienti, rapportini collegati e storico lavaggi per impianto";

    public function handle(): int
    {
        $lavaggi = Lavaggio::with(['customer', 'maintenanceSchedule.machineUnit', 'serviceReport.technician'])
            ->orderBy('customer_id')
            ->orderBy('maintenance_schedule_id')
            ->orderBy('data')
            ->get();

        $spreadsheet = new Spreadsheet;

        $this->buildClientiSheet($spreadsheet, $lavaggi);
        $this->buildRapportiniSheet($spreadsheet, $lavaggi);
        $this->buildStoricoSheet($spreadsheet, $lavaggi);

        // Il foglio vuoto creato di default da PhpSpreadsheet e' sempre il primo (indice 0):
        // i tre fogli utili vengono APPESI dopo con createSheet(), quindi e' l'indice 0 da
        // togliere, non l'ultimo (bug gia' visto: removeSheetByIndex(3) cancellava "Storico
        // per impianto" invece del foglio vuoto, perche' finiva per essere lui il 4o foglio).
        $spreadsheet->removeSheetByIndex(0);
        $spreadsheet->setActiveSheetIndex(0);

        $path = base_path($this->option('path'));
        (new Xlsx($spreadsheet))->save($path);

        $this->info("Creato: {$path}");

        return self::SUCCESS;
    }

    private function buildClientiSheet(Spreadsheet $spreadsheet, $lavaggi): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Clienti');
        $sheet->fromArray(['Cliente', 'Città', 'N. impianti', 'N. lavaggi registrati', 'N. collegati a rapportino', 'Ultimo lavaggio', 'Prossima scadenza più vicina'], null, 'A1');

        $byCustomer = $lavaggi->groupBy('customer_id');
        $schedulesByCustomer = MaintenanceSchedule::where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->get()
            ->groupBy('customer_id');

        $row = 2;
        foreach ($byCustomer as $customerId => $rows) {
            $customer = $rows->first()->customer;
            $schedules = $schedulesByCustomer->get($customerId, collect());

            $sheet->fromArray([
                $customer?->company_name ?? $customer?->full_name ?? $customerId,
                $customer?->city,
                $schedules->count(),
                $rows->count(),
                $rows->whereNotNull('service_report_id')->count(),
                optional($rows->max('data'))->format('d/m/Y'),
                optional($schedules->whereNotNull('next_due_date')->min('next_due_date'))->format('d/m/Y'),
            ], null, "A{$row}");
            $row++;
        }

        $this->autoSize($sheet, 7);
    }

    private function buildRapportiniSheet(Spreadsheet $spreadsheet, $lavaggi): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Rapportini collegati');
        $sheet->fromArray(['Cliente', 'Impianto', 'Data lavaggio', 'N. rapportino', 'Data intervento', 'Tipo intervento', 'Stato', 'Tecnico'], null, 'A1');

        $row = 2;
        foreach ($lavaggi->whereNotNull('service_report_id') as $lavaggio) {
            $report = $lavaggio->serviceReport;

            $sheet->fromArray([
                $lavaggio->customer?->company_name ?? $lavaggio->customer?->full_name,
                $this->impiantoLabel($lavaggio),
                $lavaggio->data->format('d/m/Y'),
                $report?->number,
                optional($report?->intervention_date)->format('d/m/Y'),
                $report?->intervention_type,
                $report?->status,
                $report?->technician?->name,
            ], null, "A{$row}");
            $row++;
        }

        $this->autoSize($sheet, 8);
    }

    private function buildStoricoSheet(Spreadsheet $spreadsheet, $lavaggi): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Storico per impianto');
        $sheet->fromArray(['Cliente', 'Impianto', 'Data lavaggio', 'Descrizione', 'Rapportino', 'Note'], null, 'A1');

        $row = 2;
        foreach ($lavaggi as $lavaggio) {
            $sheet->fromArray([
                $lavaggio->customer?->company_name ?? $lavaggio->customer?->full_name,
                $this->impiantoLabel($lavaggio),
                $lavaggio->data->format('d/m/Y'),
                $lavaggio->descrizione,
                $lavaggio->serviceReport?->number ?? '— (inserito a mano)',
                $lavaggio->note,
            ], null, "A{$row}");
            $row++;
        }

        $this->autoSize($sheet, 6);
    }

    private function impiantoLabel(Lavaggio $lavaggio): string
    {
        $schedule = $lavaggio->maintenanceSchedule;

        if (! $schedule) {
            return '—';
        }

        return $schedule->machineUnit?->serial_number ?? $schedule->beverage_type ?? $schedule->id;
    }

    private function autoSize(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $columns): void
    {
        foreach (range('A', \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columns)) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columns).'1')->getFont()->setBold(true);
    }
}
