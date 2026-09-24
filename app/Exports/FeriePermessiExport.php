<?php

namespace App\Exports;

use App\Filament\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Support\DisplayName;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * L'export di ferie, permessi e malattie (24/09/2026).
 *
 * Stessa storia di TimbratureExport: colonne esplicite al posto del
 * `->fromTable()` di pxlrbt/filament-excel, che pretendeva Filament 4.
 *
 * Il permesso e' orario e gli altri tipi sono a giorni: la colonna
 * "Giorni/Ore" porta l'unita' scritta accanto al numero, come a schermo,
 * perche' altrimenti nel foglio due numeri della stessa colonna vorrebbero
 * dire cose diverse.
 */
class FeriePermessiExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @param  Collection<int, LeaveRequest>  $righe */
    public function __construct(private Collection $righe) {}

    public function collection(): Collection
    {
        return $this->righe;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Dipendente', 'Tipo', 'Dal', 'Al', 'Giorni/Ore', 'Stato'];
    }

    /**
     * @param  LeaveRequest  $row
     * @return array<int, string|null>
     */
    public function map($row): array
    {
        return [
            DisplayName::titleCase($row->user?->name) ?? '—',
            LeaveRequestResource::typeLabels()[$row->type] ?? $row->type,
            $row->date_from->format('d/m/Y'),
            $row->date_to->format('d/m/Y'),
            $row->type === 'permesso'
                ? number_format((float) $row->hours, 2).' h'
                : $row->days.' gg',
            LeaveRequestResource::statusLabels()[$row->status] ?? ucfirst((string) $row->status),
        ];
    }
}
