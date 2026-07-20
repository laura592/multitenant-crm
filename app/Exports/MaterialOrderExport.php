<?php

namespace App\Exports;

use App\Models\MaterialOrder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MaterialOrderExport implements FromCollection, WithHeadings
{
    public function __construct(protected MaterialOrder $order) {}

    public function headings(): array
    {
        return ['Categoria', 'Codice', 'Tipo', 'Variante', 'Tubo Ø', 'Tubo Ø (2)', 'Filetto', 'Tipo filetto', 'Codolo Ø', 'Quantità'];
    }

    public function collection(): Collection
    {
        return $this->order->items
            ->sortBy(fn ($item) => $item->material->category.$item->material->code)
            ->map(fn ($item) => [
                $item->material->category,
                $item->material->code,
                $item->material->type,
                $item->material->variant,
                $item->material->tube_diameter,
                $item->material->tube_diameter_2,
                $item->material->thread_size,
                $item->material->thread_type,
                $item->material->barb_diameter,
                $item->quantity,
            ])
            ->values();
    }
}
