<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DailyTimeDetailExport implements FromCollection, WithHeadings
{
    public function __construct(protected Collection $rows) {}

    public function headings(): array
    {
        return ['Dipendente', 'Data', 'Ore lavorate', 'Ordinarie', 'Straordinario', 'Assenza'];
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => [
            $row['user'],
            $row['date']->format('d/m/Y'),
            $row['ore_lavorate'],
            $row['ordinarie'],
            $row['straordinario'],
            $row['assenza'] ?? '',
        ]);
    }
}
