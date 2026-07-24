<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MonthlyTimeSummaryExport implements FromCollection, WithHeadings
{
    public function __construct(protected Collection $rows) {}

    public function headings(): array
    {
        return ['Dipendente', 'Ore ordinarie', 'Straordinario', 'Giorni ferie', 'Giorni malattia', 'Ore permesso'];
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => [
            $row['user'],
            $row['ordinarie'],
            $row['straordinario'],
            $row['ferie_giorni'],
            $row['malattia_giorni'],
            $row['permessi_ore'],
        ]);
    }
}
